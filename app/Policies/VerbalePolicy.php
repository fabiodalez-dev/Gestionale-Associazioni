<?php

namespace App\Policies;

use App\Models\Utente;
use App\Models\Verbale;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy del modulo verbali: abilità mappate sui permessi spatie
 * (verbali.view / create / update / delete) con vincoli aggiuntivi:
 * - approvazione: solo admin_associazione / super_admin;
 * - modifica/eliminazione di verbali approvati: solo admin
 *   (l'update azzera l'approvazione);
 * - cestino (restore/forceDelete): solo admin.
 */
class VerbalePolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'verbali.view', self::FALLBACK);
    }

    public function view(Utente $utente, Verbale $verbale): bool
    {
        return $this->allowed($utente, 'verbali.view', self::FALLBACK)
            && $this->stessoTenant($utente, $verbale);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'verbali.create', self::FALLBACK);
    }

    public function update(Utente $utente, Verbale $verbale): bool
    {
        if (! $this->allowed($utente, 'verbali.update', self::FALLBACK)
            || ! $this->stessoTenant($utente, $verbale)) {
            return false;
        }

        // I verbali approvati sono modificabili solo dagli admin
        return $verbale->approvato ? $utente->isAdminAssociazione() : true;
    }

    public function approve(Utente $utente, Verbale $verbale): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $verbale);
    }

    public function delete(Utente $utente, Verbale $verbale): bool
    {
        if (! $this->allowed($utente, 'verbali.delete', self::FALLBACK)
            || ! $this->stessoTenant($utente, $verbale)) {
            return false;
        }

        // I verbali approvati sono eliminabili solo dagli admin
        return $verbale->approvato ? $utente->isAdminAssociazione() : true;
    }

    public function restore(Utente $utente, Verbale $verbale): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $verbale);
    }

    public function forceDelete(Utente $utente, Verbale $verbale): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $verbale);
    }

    private function stessoTenant(Utente $utente, Verbale $verbale): bool
    {
        return $utente->associazione_id === $verbale->associazione_id;
    }
}
