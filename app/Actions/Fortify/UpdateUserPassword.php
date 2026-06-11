<?php

namespace App\Actions\Fortify;

use App\Models\Utente;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Aggiorna la password dell'utente (colonna legacy `password_hash`).
     *
     * @param  Utente  $user
     * @param  array<string, string>  $input
     */
    public function update($user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ], [
            'current_password.current_password' => 'La password attuale non è corretta.',
        ])->validateWithBag('updatePassword');

        // Il cast 'hashed' sul model esegue l'hashing
        $user->forceFill([
            'password_hash' => $input['password'],
        ])->save();
    }
}
