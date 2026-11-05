<?php

namespace App\Services\Rinnovi;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Pagamenti\PaymentService;
use App\Services\Tessere\TesseraIssuer;

/**
 * Rinnovo tesseramento self-service (f.1 del piano Vita sociale).
 *
 * Un socio è "da rinnovare" per l'anno corrente quando non ha una tessera
 * per l'anno. Il rinnovo crea (se mancante) la quota dell'anno via
 * PaymentService::createQuotaForSocio, genera/riusa il payment_token e
 * porta alla pagina di pagamento pubblica esistente; alla conferma del
 * pagamento la tessera dell'anno viene emessa dall'hook in
 * PaymentService::onPaymentCompleted (esteso per i soci già attivi).
 *
 * Rinnovo anticipato: con la tessera dell'anno già emessa, il rinnovo per
 * l'anno successivo è disponibile quando la tessera corrente è nella
 * finestra dei promemoria di rinnovo (che invitano a rinnovare dall'area
 * soci) oppure quando la segreteria ha già aperto la quota dell'anno dopo.
 */
class RinnovoService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly TesseraIssuer $tessere,
    ) {}

    public function annoCorrente(): int
    {
        return (int) now()->year;
    }

    /**
     * Anno per cui il socio può rinnovare adesso: l'anno corrente se manca la
     * tessera, l'anno successivo nella finestra del rinnovo anticipato,
     * altrimenti l'anno corrente (rinnovo non disponibile, vedi puoRinnovare).
     */
    public function annoRinnovo(Socio $socio): int
    {
        $anno = $this->annoCorrente();

        if (! $this->haTesseraAnno($socio, $anno)) {
            return $anno;
        }

        return $this->rinnovoAnticipatoDisponibile($socio, $anno + 1) ? $anno + 1 : $anno;
    }

    /**
     * Il socio può avviare il rinnovo self-service?
     * Solo soci Attivi senza tessera per l'anno di rinnovo.
     */
    public function puoRinnovare(Socio $socio, ?int $anno = null): bool
    {
        return $socio->isAttivo() && ! $this->haTesseraAnno($socio, $anno ?? $this->annoRinnovo($socio));
    }

    public function haTesseraAnno(Socio $socio, int $anno): bool
    {
        return Tessera::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('anno_validita', $anno)
            ->exists();
    }

    /**
     * Quota per il rinnovo dell'anno indicato (default: annoRinnovo): riusa
     * una quota non pagata esistente, altrimenti la crea con l'importo del
     * tipo socio (fallback costo tessera associazione). Garantisce un
     * payment_token valido.
     *
     * Restituisce null se il rinnovo non è disponibile (socio non attivo
     * o tessera dell'anno già presente) oppure se la quota dell'anno risulta
     * già pagata: in quel caso non si chiede un secondo pagamento e la
     * tessera dell'anno, se manca, viene emessa subito.
     */
    public function quotaPerRinnovo(Socio $socio, ?int $anno = null): ?Quota
    {
        $anno = $anno ?? $this->annoRinnovo($socio);

        if (! $this->puoRinnovare($socio, $anno)) {
            return null;
        }

        $quoteAnno = Quota::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('anno', $anno);

        if ((clone $quoteAnno)->whereNotNull('data_pagamento')->exists()) {
            try {
                $this->tessere->emettiSeAssente($socio, $anno);
            } catch (\Throwable $e) {
                // Best-effort: la segreteria può sempre emetterla a mano
                report($e);
            }

            return null;
        }

        /** @var Quota|null $quota */
        $quota = $quoteAnno
            ->whereNull('data_pagamento')
            ->orderBy('data_scadenza')
            ->first();

        if ($quota === null) {
            $quota = PaymentService::createQuotaForSocio($socio, $this->importoRinnovo($socio), anno: $anno);
        }

        if (! $quota->hasValidPaymentToken()) {
            $this->payments->generatePaymentToken($quota);
            $quota->refresh();
        }

        return $quota;
    }

    /**
     * Importo del rinnovo: costo tessera del tipo socio con fallback
     * sul costo tessera dell'associazione (parità con la scheda socio).
     */
    public function importoRinnovo(Socio $socio): float
    {
        $socio->loadMissing(['tipoSocio', 'associazione']);

        $importo = $socio->tipoSocio->costo_tessera ?? $socio->associazione->costo_tessera ?? null;

        return (float) ($importo ?? 0);
    }

    /**
     * Rinnovo anticipato per $anno (= anno corrente + 1): quota di quell'anno
     * già aperta e non pagata, oppure tessera attiva dell'anno corrente in
     * scadenza entro lo step massimo dei promemoria di rinnovo del tenant.
     */
    private function rinnovoAnticipatoDisponibile(Socio $socio, int $anno): bool
    {
        $quoteAnno = Quota::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('anno', $anno);

        if ((clone $quoteAnno)->whereNull('data_pagamento')->exists()) {
            return true;
        }

        // Quota dell'anno già pagata ma tessera mai emessa (emissione
        // best-effort fallita al pagamento): il rinnovo resta disponibile
        // per ritentarla, quotaPerRinnovo la emette senza chiedere un nuovo
        // pagamento. Con la tessera già emessa nulla cambia.
        if ((clone $quoteAnno)->whereNotNull('data_pagamento')->exists() && ! $this->haTesseraAnno($socio, $anno)) {
            return true;
        }

        $steps = $socio->associazione()->first()?->promemoriaRinnovoSteps() ?? [];

        if ($steps === []) {
            return false;
        }

        return Tessera::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('anno_validita', $anno - 1)
            ->where('stato', Tessera::STATO_ATTIVA)
            ->whereDate('data_scadenza', '>=', now()->toDateString())
            ->whereDate('data_scadenza', '<=', now()->addDays(max($steps))->toDateString())
            ->exists();
    }
}
