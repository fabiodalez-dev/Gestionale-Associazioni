<?php

namespace App\Support;

use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Account a cui si riferisce un tentativo di accesso fallito.
 *
 * Fortify emette l'evento Failed con utente nullo anche quando l'email esiste
 * (password errata): per attribuire il tentativo al suo tenant, l'account va
 * cercato tramite il provider della guard usata ('web', 'socio', 'account').
 */
final class AccountTentativoAccesso
{
    public static function risolvi(Failed $event): ?Authenticatable
    {
        if ($event->user instanceof Authenticatable) {
            return $event->user;
        }

        $email = self::emailNormalizzata($event->credentials['email'] ?? null);
        if ($email === '') {
            return null;
        }

        // Le email di account e soci sono uniche solo per associazione: senza
        // il tenant pubblico risolto (richiesta fuori da /a/{slug}) la ricerca
        // prenderebbe un account a caso e l'avviso andrebbe all'admin sbagliato.
        // Solo la guard 'web' (utenti) ha l'email unica a livello globale.
        if ($event->guard !== 'web' && ! app()->bound('tenant.pubblico')) {
            return null;
        }

        try {
            $account = Auth::guard($event->guard)->getProvider()->retrieveByCredentials(['email' => $email]);
        } catch (\Throwable) {
            return null;
        }

        return $account instanceof Authenticatable ? $account : null;
    }

    /** Forma unica dell'email tentata (trim + minuscolo), usata da audit e avvisi. */
    public static function emailNormalizzata(mixed $email): string
    {
        return is_string($email) ? mb_strtolower(trim($email)) : '';
    }
}
