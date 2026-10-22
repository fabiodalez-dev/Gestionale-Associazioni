<?php

namespace App\Actions\Fortify;

use App\Models\Utente;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * Imposta la nuova password dopo il link di recupero e chiude gli accessi
     * esistenti: cookie "Ricordami" invalidati e sessioni attive eliminate.
     *
     * @param  Utente  $user
     * @param  array<string, string>  $input
     */
    public function reset($user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules([$user->email, $user->nome, $user->cognome])],
        ], PasswordPolicy::messages())->validate();

        // Il cast 'hashed' sul model esegue l'hashing
        $user->forceFill([
            'password_hash' => $input['password'],
            'remember_token' => Str::random(60),
        ])->save();

        // Sessioni attive su altri dispositivi (driver database)
        $tabellaSessioni = (string) config('session.table', 'sessions');
        if (Schema::hasTable($tabellaSessioni)) {
            DB::table($tabellaSessioni)->where('user_id', $user->getKey())->delete();
        }
    }
}
