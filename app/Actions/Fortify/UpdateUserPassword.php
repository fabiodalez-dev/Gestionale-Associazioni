<?php

namespace App\Actions\Fortify;

use App\Models\Utente;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Aggiorna la password dell'utente (colonna legacy `password_hash`) e,
     * come ResetUserPassword, chiude gli accessi aperti altrove: il
     * remember_token ruota (ogni cookie "Ricordami" emesso finora non vale
     * più) e le sessioni DB sugli altri dispositivi vengono eliminate. La
     * sessione corrente, da cui l'utente ha appena cambiato la password,
     * resta aperta.
     *
     * @param  Utente  $user
     * @param  array<string, string>  $input
     */
    public function update($user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', ...PasswordPolicy::rules([$user->email, $user->nome, $user->cognome])],
        ], [
            'current_password.current_password' => 'La password attuale non è corretta.',
            'password.different' => 'La nuova password deve essere diversa da quella attuale.',
            ...PasswordPolicy::messages(),
        ])->validateWithBag('updatePassword');

        // Il cast 'hashed' sul model esegue l'hashing; il remember_token ruota
        // così i cookie "Ricordami" già emessi non valgono più
        $user->forceFill([
            'password_hash' => $input['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $this->chiudiAltreSessioni($user);
    }

    /**
     * Elimina le sessioni (driver database) dell'utente diverse da quella
     * corrente: gli altri dispositivi dovranno accedere di nuovo.
     */
    private function chiudiAltreSessioni(Utente $user): void
    {
        $tabella = (string) config('session.table', 'sessions');
        if (! Schema::hasTable($tabella)) {
            return;
        }

        $query = DB::table($tabella)->where('user_id', $user->getKey());

        $request = request();
        if ($request->hasSession()) {
            $query->where('id', '!=', $request->session()->getId());
        }

        $query->delete();
    }
}
