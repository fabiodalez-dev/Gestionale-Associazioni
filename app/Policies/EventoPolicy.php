<?php

namespace App\Policies;

use App\Models\Evento;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy del modulo eventi: abilità mappate sui permessi spatie
 * (eventi.view / create / update / delete) con fallback per ruolo quando
 * i permessi non sono seedati. Il super_admin bypassa tutto; le abilità
 * sul singolo modello verificano anche l'appartenenza al tenant
 * (difesa in profondità oltre al global scope BelongsToAssociazione).
 */
class EventoPolicy
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

    public function view(Utente $utente, Evento $evento): bool
    {
        return $this->allowed($utente, 'eventi.view', self::FALLBACK)
            && $this->stessoTenant($utente, $evento);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'eventi.create', self::FALLBACK);
    }

    public function update(Utente $utente, Evento $evento): bool
    {
        return $this->allowed($utente, 'eventi.update', self::FALLBACK)
            && $this->stessoTenant($utente, $evento);
    }

    public function delete(Utente $utente, Evento $evento): bool
    {
        return $this->allowed($utente, 'eventi.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $evento);
    }

    private function stessoTenant(Utente $utente, Evento $evento): bool
    {
        return $utente->associazione_id === $evento->associazione_id;
    }
}
