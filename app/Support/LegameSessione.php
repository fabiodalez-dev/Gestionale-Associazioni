<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Lega la sessione di un account pubblico o di un socio alla sua credenziale.
 *
 * Alla prima richiesta autenticata la sessione memorizza un'impronta di
 * password ed email verificata; se cambia (reimpostazione, nuova password,
 * verifica dell'email) la sessione non vale più e chi la teneva deve accedere
 * di nuovo. Le sessioni già aperte prima dell'aggiornamento si legano alla
 * loro prima richiesta, quindi restano valide.
 */
final class LegameSessione
{
    public static function impronta(Authenticatable $utente): string
    {
        $verificata = $utente->getAttribute('email_verified_at');

        return hash('sha256', $utente->getAuthPassword().'|'.($verificata === null ? '' : (string) $verificata));
    }

    /**
     * True se la sessione è ancora legata alla credenziale corrente (o se
     * non era ancora legata: in quel caso la lega).
     */
    public static function valida(Request $request, string $guard): bool
    {
        $utente = auth()->guard($guard)->user();
        if ($utente === null || ! $request->hasSession()) {
            return true;
        }

        $chiave = self::chiave($guard);
        $salvata = $request->session()->get($chiave);
        $attuale = self::valore($utente);

        // Non legata, o legata a un altro utente (cambio di utente nella stessa
        // sessione): si lega a quello di ora
        if (! is_string($salvata) || ! str_starts_with($salvata, self::prefisso($utente))) {
            $request->session()->put($chiave, $attuale);

            return true;
        }

        return hash_equals($salvata, $attuale);
    }

    /**
     * Chiude la sessione dell'utente che non passa più la verifica.
     */
    public static function chiudi(Request $request, string $guard): void
    {
        auth()->guard($guard)->logout();

        if ($request->hasSession()) {
            $request->session()->forget(self::chiave($guard));
        }
    }

    /**
     * Da chiamare quando è la persona autenticata a cambiare la propria
     * credenziale: la sua sessione resta valida, le altre no.
     */
    public static function aggiorna(Request $request, string $guard, Authenticatable $utente): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::chiave($guard), self::valore($utente));
        }
    }

    private static function prefisso(Authenticatable $utente): string
    {
        return (string) $utente->getAuthIdentifier().'|';
    }

    private static function valore(Authenticatable $utente): string
    {
        return self::prefisso($utente).self::impronta($utente);
    }

    private static function chiave(string $guard): string
    {
        return 'legame_sessione_'.$guard;
    }
}
