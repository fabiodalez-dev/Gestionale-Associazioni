<?php

namespace App\Policies;

use App\Models\Mansione;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Mansioni/competenze (f.2 del piano "Organizzazione 3"): tassonomia di
 * configurazione del tenant. Gestione riservata ai profili amministrativi
 * (famiglia permessi soci.*, coerente con il profilo volontario del socio).
 * Il super_admin bypassa; le abilità sul modello verificano il tenant
 * (difesa in profondità oltre al global scope BelongsToAssociazione).
 */
class MansionePolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'volontari.view', self::FALLBACK);
    }

    public function view(Utente $utente, Mansione $mansione): bool
    {
        return $this->allowed($utente, 'volontari.view', self::FALLBACK)
            && $this->stessoTenant($utente, $mansione);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'volontari.update', self::FALLBACK);
    }

    public function update(Utente $utente, Mansione $mansione): bool
    {
        return $this->allowed($utente, 'volontari.update', self::FALLBACK)
            && $this->stessoTenant($utente, $mansione);
    }

    public function delete(Utente $utente, Mansione $mansione): bool
    {
        return $this->allowed($utente, 'volontari.update', self::FALLBACK)
            && $this->stessoTenant($utente, $mansione);
    }

    private function stessoTenant(Utente $utente, Mansione $mansione): bool
    {
        return $utente->associazione_id === $mansione->associazione_id;
    }
}
