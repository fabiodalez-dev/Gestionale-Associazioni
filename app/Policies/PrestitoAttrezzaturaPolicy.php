<?php

namespace App\Policies;

use App\Models\PrestitoAttrezzatura;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy prestiti attrezzature (f.10 piano "Organizzazione 3"): permessi
 * della famiglia eventi.*, fallback per ruolo, verifica del tenant.
 */
class PrestitoAttrezzaturaPolicy
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

    public function view(Utente $utente, PrestitoAttrezzatura $prestito): bool
    {
        return $this->allowed($utente, 'spazi.view', self::FALLBACK)
            && $this->stessoTenant($utente, $prestito);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'spazi.create', self::FALLBACK);
    }

    public function update(Utente $utente, PrestitoAttrezzatura $prestito): bool
    {
        return $this->allowed($utente, 'spazi.update', self::FALLBACK)
            && $this->stessoTenant($utente, $prestito);
    }

    public function delete(Utente $utente, PrestitoAttrezzatura $prestito): bool
    {
        return $this->allowed($utente, 'spazi.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $prestito);
    }

    private function stessoTenant(Utente $utente, PrestitoAttrezzatura $prestito): bool
    {
        return $utente->associazione_id === $prestito->associazione_id;
    }
}
