<?php

namespace App\Policies;

use App\Models\DiscussioneSpazio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Moderazione delle discussioni tra soci (issue #13): famiglia di permessi
 * comunicazione.* (come annunci, newsletter e sondaggi); senza permessi
 * seedati solo gli admin dell'associazione.
 */
class DiscussioneSpazioPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = [Utente::RUOLO_ADMIN_ASSOCIAZIONE];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'comunicazione.view', self::FALLBACK);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'comunicazione.create', self::FALLBACK);
    }

    public function update(Utente $utente, DiscussioneSpazio $spazio): bool
    {
        return $this->allowed($utente, 'comunicazione.update', self::FALLBACK)
            && $utente->associazione_id === $spazio->associazione_id;
    }
}
