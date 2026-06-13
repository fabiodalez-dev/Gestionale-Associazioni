<?php

namespace App\Support\Relazioni;

use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\View;

/**
 * Programma referral "porta un amico" (f.23 del piano "Organizzazione 3"),
 * agganciato SENZA toccare il flusso d'iscrizione di un altro modulo.
 *
 * Meccanica:
 * 1. quando viene reso il form pubblico /a/{slug}/iscriviti?ref=CODICE,
 *    un view composer memorizza il codice in sessione (se valido per il
 *    tenant) e lo espone al partial come hidden field;
 * 2. alla creazione di una RichiestaIscrizione (model event), il codice in
 *    sessione viene scritto su referral_codice — solo se non già valorizzato
 *    e se corrisponde a un socio reale del tenant. Un eventuale errore non
 *    deve MAI far fallire l'iscrizione (best-effort).
 *
 * Registrato al boot dal file di rotte routes/modules/relazioni.php.
 */
class RelazioniReferral
{
    /** Chiave di sessione del codice referral catturato dall'URL. */
    public const SESSION_KEY = 'relazioni.referral_codice';

    public static function register(): void
    {
        // Cattura ?ref= alla visualizzazione del form pubblico d'iscrizione
        ViewFacade::composer('pubblico.iscrizione.form', function (View $view) {
            $codice = self::codiceValidoDallaRichiesta();

            if ($codice !== null) {
                session([self::SESSION_KEY => $codice]);
            }

            // Esposto al partial (hidden field): preferenza all'URL corrente,
            // poi a quanto eventualmente già in sessione.
            $view->with('referralCodice', $codice ?? self::codiceInSessione());
        });

        // Salva il referral sulla richiesta appena creata (best-effort)
        RichiestaIscrizione::created(function (RichiestaIscrizione $richiesta) {
            try {
                if (filled($richiesta->referral_codice)) {
                    return; // già impostato a monte: non sovrascrivere
                }

                $codice = request()->input('referral_codice')
                    ?? request()->input('ref')
                    ?? self::codiceInSessione();

                if (! is_string($codice) || $codice === '') {
                    return;
                }

                if (! self::esisteSocioConCodice($codice, $richiesta->associazione_id)) {
                    return;
                }

                $richiesta->forceFill(['referral_codice' => $codice])->saveQuietly();

                session()->forget(self::SESSION_KEY);
            } catch (\Throwable) {
                // Mai compromettere l'iscrizione per un problema di referral
            }
        });
    }

    /**
     * Codice referral valido (esiste un socio del tenant corrente con quel
     * codice) preso dall'URL ?ref=, oppure null.
     */
    private static function codiceValidoDallaRichiesta(): ?string
    {
        $codice = request()->query('ref');

        if (! is_string($codice) || trim($codice) === '') {
            return null;
        }

        $codice = trim($codice);

        return self::esisteSocioConCodice($codice) ? $codice : null;
    }

    private static function codiceInSessione(): ?string
    {
        $codice = session(self::SESSION_KEY);

        return is_string($codice) && $codice !== '' ? $codice : null;
    }

    /**
     * Esiste un socio con quel codice referral nel tenant indicato?
     * Senza $associazioneId si affida al global scope (tenant pubblico/admin).
     */
    private static function esisteSocioConCodice(string $codice, ?string $associazioneId = null): bool
    {
        if ($associazioneId !== null) {
            return Socio::query()
                ->withoutGlobalScopes()
                ->where('associazione_id', $associazioneId)
                ->where('codice_referral', $codice)
                ->exists();
        }

        return Socio::query()->where('codice_referral', $codice)->exists();
    }
}
