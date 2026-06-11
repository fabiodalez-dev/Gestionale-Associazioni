<?php

namespace App\Policies;

use App\Models\CategoriaSocio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Configurazione categorie socio: solo admin_associazione e super_admin (spec soci §13).
 */
class CategoriaSocioPolicy
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

    public function update(Utente $utente, CategoriaSocio $categoriaSocio): bool
    {
        return $this->allowedConfig($utente);
    }

    public function delete(Utente $utente, CategoriaSocio $categoriaSocio): bool
    {
        return $this->allowedConfig($utente);
    }
}
