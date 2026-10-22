<?php

namespace App\Policies;

use App\Models\Attrezzatura;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy inventario attrezzature (f.9 piano "Organizzazione 3"): permessi
 * della famiglia eventi.*, fallback per ruolo, verifica del tenant.
 */
class AttrezzaturaPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'spazi.view', self::FALLBACK);
    }

    public function view(Utente $utente, Attrezzatura $attrezzatura): bool
    {
        return $this->allowed($utente, 'spazi.view', self::FALLBACK)
            && $this->stessoTenant($utente, $attrezzatura);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'spazi.create', self::FALLBACK);
    }

    public function update(Utente $utente, Attrezzatura $attrezzatura): bool
    {
        return $this->allowed($utente, 'spazi.update', self::FALLBACK)
            && $this->stessoTenant($utente, $attrezzatura);
    }

    public function delete(Utente $utente, Attrezzatura $attrezzatura): bool
    {
        return $this->allowed($utente, 'spazi.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $attrezzatura);
    }

    private function stessoTenant(Utente $utente, Attrezzatura $attrezzatura): bool
    {
        return $utente->associazione_id === $attrezzatura->associazione_id;
    }
}
