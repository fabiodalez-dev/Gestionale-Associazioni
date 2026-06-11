<?php

namespace App\Services\Email;

use App\Models\Associazione;
use App\Models\Socio;
use Throwable;

/**
 * Wrapper base responsive delle email in uscita (porting di wrapInEmailTemplate):
 * layout email-safe a tabelle 600px, header brand #FF7B11 con logo e nome
 * associazione, footer con © anno e link di disiscrizione.
 *
 * In Laravel il layout viene applicato in modo uniforme a tutte le email
 * accodate (§11), con link unsubscribe sempre presente quando c'è un socio.
 */
class EmailLayout
{
    /**
     * Avvolge il body nel layout base. Fail-safe: in caso di errore di
     * rendering ritorna il body nudo (come il legacy con file mancante).
     */
    public function wrap(string $bodyHtml, Associazione $associazione, ?Socio $socio = null): string
    {
        try {
            $unsubscribeUrl = $socio !== null ? EmailPlaceholders::unsubscribeUrl($socio) : null;

            return view('email.layout-base', [
                'body' => $bodyHtml,
                'associazioneNome' => $associazione->nome,
                'logoUrl' => $associazione->logo_url,
                'unsubscribeUrl' => $unsubscribeUrl,
                'anno' => now()->format('Y'),
            ])->render();
        } catch (Throwable $e) {
            report($e);

            return $bodyHtml;
        }
    }
}
