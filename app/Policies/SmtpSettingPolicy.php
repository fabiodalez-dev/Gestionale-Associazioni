<?php

namespace App\Policies;

use App\Models\Utente;

/**
 * Impostazioni SMTP dell'associazione: solo admin dell'associazione e super_admin.
 * Chi controlla il server di posta può leggere tutte le email in uscita (compresi
 * i link di reset password degli account pubblici), quindi non basta email.update:
 * le cariche (presidente, segretario…) inviano comunicazioni ma non cambiano l'SMTP.
 */
class SmtpSettingPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->isAdminAssociazione() && $utente->can('email.update');
    }

    public function update(Utente $utente): bool
    {
        return $utente->isAdminAssociazione() && $utente->can('email.update');
    }

    public function test(Utente $utente): bool
    {
        return $utente->isAdminAssociazione() && $utente->can('email.update');
    }
}
