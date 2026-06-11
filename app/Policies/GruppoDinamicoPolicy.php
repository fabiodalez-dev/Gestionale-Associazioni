<?php

namespace App\Policies;

use App\Models\GruppoDinamico;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Configurazione gruppi dinamici: solo admin_associazione e super_admin (spec soci §13).
 */
class GruppoDinamicoPolicy
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

    public function update(Utente $utente, GruppoDinamico $gruppoDinamico): bool
    {
        return $this->allowedConfig($utente);
    }

    public function delete(Utente $utente, GruppoDinamico $gruppoDinamico): bool
    {
        return $this->allowedConfig($utente);
    }
}
