<?php

namespace App\Support;

use Illuminate\Auth\Events\Failed;
use Illuminate\Database\Eloquent\Model;
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
    public static function risolvi(Failed $event): ?Model
    {
        if ($event->user instanceof Model) {
            return $event->user;
        }

        $email = isset($event->credentials['email']) ? trim((string) $event->credentials['email']) : '';
        if ($email === '') {
            return null;
        }

        try {
            $account = Auth::guard($event->guard)->getProvider()?->retrieveByCredentials(['email' => $email]);
        } catch (\Throwable) {
            return null;
        }

        return $account instanceof Model ? $account : null;
    }
}
