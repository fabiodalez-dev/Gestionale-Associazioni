<?php

namespace App\Services\Rinnovi;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Pagamenti\PaymentService;

/**
 * Rinnovo tesseramento self-service (f.1 del piano Vita sociale).
 *
 * Un socio è "da rinnovare" per l'anno corrente quando non ha una tessera
 * per l'anno. Il rinnovo crea (se mancante) la quota dell'anno via
 * PaymentService::createQuotaForSocio, genera/riusa il payment_token e
 * porta alla pagina di pagamento pubblica esistente; alla conferma del
 * pagamento la tessera dell'anno viene emessa dall'hook in
 * PaymentService::onPaymentCompleted (esteso per i soci già attivi).
 */
class RinnovoService
{
    public function __construct(private readonly PaymentService $payments) {}

    public function annoCorrente(): int
    {
        return (int) now()->year;
    }

    /**
     * Il socio può avviare il rinnovo self-service?
     * Solo soci Attivi senza tessera per l'anno corrente.
     */
    public function puoRinnovare(Socio $socio): bool
    {
        return $socio->isAttivo() && ! $this->haTesseraAnno($socio, $this->annoCorrente());
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
     * Quota per il rinnovo dell'anno corrente: riusa una quota non pagata
     * esistente, altrimenti la crea con l'importo del tipo socio
     * (fallback costo tessera associazione). Garantisce un payment_token valido.
     *
     * Restituisce null se il rinnovo non è disponibile (socio non attivo
     * o tessera dell'anno già presente).
     */
    public function quotaPerRinnovo(Socio $socio): ?Quota
    {
        if (! $this->puoRinnovare($socio)) {
            return null;
        }

        $anno = $this->annoCorrente();

        /** @var Quota|null $quota */
        $quota = Quota::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('anno', $anno)
            ->whereNull('data_pagamento')
            ->orderBy('data_scadenza')
            ->first();

        if ($quota === null) {
            $quota = PaymentService::createQuotaForSocio($socio, $this->importoRinnovo($socio));
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
}
