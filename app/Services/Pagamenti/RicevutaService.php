<?php

namespace App\Services\Pagamenti;

use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\Socio;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Ricevute di pagamento quota (f.21 PIANO-VITA-SOCIALE): una sola
 * ricevuta per quota (unique a DB), numero progressivo per tenant e
 * anno (es. "12/2026") generato sotto lock, PDF dompdf con il layout
 * della ricevuta legacy + numerazione.
 */
class RicevutaService
{
    /**
     * Emette (idempotente) la ricevuta per una quota pagata.
     * Ritorna null se la quota non risulta pagata; se la ricevuta esiste
     * già la restituisce senza duplicarla (anche in caso di race sul
     * vincolo unique).
     */
    public function emettiPerQuota(Quota $quota): ?Ricevuta
    {
        if (! $quota->isPagata()) {
            return null;
        }

        $esistente = Ricevuta::allTenants()->where('quota_id', $quota->id)->first();

        if ($esistente !== null) {
            return $esistente;
        }

        try {
            return DB::transaction(function () use ($quota): Ricevuta {
                $anno = (int) $quota->anno;

                // Progressivo per (associazione, anno) sotto lock:
                // evita numeri duplicati con webhook concorrenti
                $maxNumero = (int) (Ricevuta::allTenants()
                    ->where('associazione_id', $quota->associazione_id)
                    ->where('anno', $anno)
                    ->lockForUpdate()
                    ->max('numero') ?? 0);

                return Ricevuta::create([
                    'associazione_id' => $quota->associazione_id,
                    'quota_id' => $quota->id,
                    'numero' => $maxNumero + 1,
                    'anno' => $anno,
                    'importo' => $quota->importo,
                    'emessa_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Race su quota_id unique: la ricevuta è stata emessa da
            // un processo concorrente, riusala
            return Ricevuta::allTenants()->where('quota_id', $quota->id)->first();
        }
    }

    /**
     * Contenuto binario del PDF della ricevuta numerata.
     */
    public function pdfContent(Ricevuta $ricevuta): string
    {
        $quota = $this->quota($ricevuta);

        $pdf = Pdf::loadView('email-conferme.ricevuta-pdf', [
            'ricevuta' => $ricevuta,
            'quota' => $quota,
            'associazione' => $quota?->associazione,
            'socio' => $this->socio($quota),
        ]);

        return (string) $pdf->output();
    }

    /**
     * Nome file standard del PDF, es. ricevuta-12-2026.pdf.
     */
    public function nomeFile(Ricevuta $ricevuta): string
    {
        return 'ricevuta-'.$ricevuta->numero.'-'.$ricevuta->anno.'.pdf';
    }

    /**
     * Quota della ricevuta senza scope tenant (worker fuori contesto);
     * usa la relation se già caricata (anteprime su modelli in memoria).
     */
    protected function quota(Ricevuta $ricevuta): ?Quota
    {
        if ($ricevuta->relationLoaded('quota')) {
            /** @var Quota|null */
            return $ricevuta->getRelation('quota');
        }

        return Quota::allTenants()->find($ricevuta->quota_id);
    }

    protected function socio(?Quota $quota): ?Socio
    {
        if ($quota === null) {
            return null;
        }

        if ($quota->relationLoaded('socio')) {
            /** @var Socio|null */
            return $quota->getRelation('socio');
        }

        return Socio::allTenants()->withTrashed()->find($quota->socio_id);
    }
}
