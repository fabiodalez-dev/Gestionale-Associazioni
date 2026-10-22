<?php

namespace App\Policies;

use App\Models\OpportunitaCrm;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * CRM a colonne: stessi permessi delle relazioni esterne (relazioni.*).
 */
class OpportunitaCrmPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'relazioni.view', self::FALLBACK);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'relazioni.update', self::FALLBACK);
    }

    public function update(Utente $utente, OpportunitaCrm $opportunita): bool
    {
        return $this->allowed($utente, 'relazioni.update', self::FALLBACK)
            && $utente->associazione_id === $opportunita->associazione_id;
    }

    public function delete(Utente $utente, OpportunitaCrm $opportunita): bool
    {
        return $this->update($utente, $opportunita);
    }
}
