<?php

namespace App\Policies;

use App\Models\Socio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Gestione soci: aperta a tutti i ruoli amministrativi (anche operatore),
 * come nel legacy (spec soci §13). Il vincolo tenant è garantito dal
 * global scope BelongsToAssociazione (cross-tenant = 404 sul binding).
 */
class SocioPolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function view(Utente $utente, Socio $socio): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.create', self::RUOLI);
    }

    public function update(Utente $utente, Socio $socio): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI);
    }

    public function delete(Utente $utente, Socio $socio): bool
    {
        return $this->allowed($utente, 'soci.delete', self::RUOLI);
    }

    public function restore(Utente $utente, Socio $socio): bool
    {
        return $this->allowed($utente, 'soci.delete', self::RUOLI);
    }

    public function forceDelete(Utente $utente, Socio $socio): bool
    {
        return $this->allowed($utente, 'soci.delete', self::RUOLI);
    }
}
