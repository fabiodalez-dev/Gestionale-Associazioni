<?php

namespace App\Policies;

use App\Models\Utente;

/**
 * Impostazioni SMTP: solo chi ha email.update può vederle e modificarle
 * (contengono la configurazione del mittente; l'operatore non vi accede).
 */
class SmtpSettingPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('email.update');
    }

    public function update(Utente $utente): bool
    {
        return $utente->can('email.update');
    }

    public function test(Utente $utente): bool
    {
        return $utente->can('email.update');
    }
}
