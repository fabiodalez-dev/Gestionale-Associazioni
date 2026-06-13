<?php

namespace App\Policies;

use App\Models\Turno;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Turni di volontariato (f.3 del piano "Organizzazione 3"): pianificazione
 * lato admin, famiglia permessi eventi.* (come da spec). Il super_admin
 * bypassa; le abilità sul modello verificano l'appartenenza al tenant.
 */
class TurnoPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'eventi.view', self::FALLBACK);
    }

    public function view(Utente $utente, Turno $turno): bool
    {
        return $this->allowed($utente, 'eventi.view', self::FALLBACK)
            && $this->stessoTenant($utente, $turno);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'eventi.create', self::FALLBACK);
    }

    public function update(Utente $utente, Turno $turno): bool
    {
        return $this->allowed($utente, 'eventi.update', self::FALLBACK)
            && $this->stessoTenant($utente, $turno);
    }

    public function delete(Utente $utente, Turno $turno): bool
    {
        return $this->allowed($utente, 'eventi.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $turno);
    }

    private function stessoTenant(Utente $utente, Turno $turno): bool
    {
        return $utente->associazione_id === $turno->associazione_id;
    }
}
