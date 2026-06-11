<?php

namespace App\Policies;

use App\Models\Utente;

/**
 * Gestione utenti amministratori: solo super_admin
 * (corregge la falla legacy di pages/amministratori.php — spec §3.1, §9.4).
 */
class UtentePolicy
{
    public function viewAny(Utente $utente): bool
    {
        return $utente->isSuperAdmin();
    }

    public function create(Utente $utente): bool
    {
        return $utente->isSuperAdmin();
    }

    public function update(Utente $utente, Utente $target): bool
    {
        return $utente->isSuperAdmin();
    }

    /**
     * Protezioni aggiunte rispetto al legacy (spec §9.16):
     * - divieto di auto-eliminazione;
     * - divieto di eliminare l'ultimo super_admin.
     */
    public function delete(Utente $utente, Utente $target): bool
    {
        if (! $utente->isSuperAdmin()) {
            return false;
        }

        if ($utente->id === $target->id) {
            return false;
        }

        if ($target->isSuperAdmin()) {
            $altriSuperAdmin = Utente::query()
                ->where('ruolo', Utente::RUOLO_SUPER_ADMIN)
                ->where('id', '<>', $target->id)
                ->count();

            return $altriSuperAdmin > 0;
        }

        return true;
    }
}
