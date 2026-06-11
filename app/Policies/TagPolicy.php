<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Configurazione tag: solo admin_associazione e super_admin (spec soci §13).
 */
class TagPolicy
{
    use AuthorizesModulePermissions;

    public function viewAny(Utente $utente): bool
    {
        return $this->allowedConfig($utente);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowedConfig($utente);
    }

    public function update(Utente $utente, Tag $tag): bool
    {
        return $this->allowedConfig($utente);
    }

    public function delete(Utente $utente, Tag $tag): bool
    {
        return $this->allowedConfig($utente);
    }
}
