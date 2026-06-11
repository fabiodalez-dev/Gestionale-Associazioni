<?php

namespace App\Policies;

use App\Models\TipoSocio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Configurazione tipi socio: solo admin_associazione e super_admin (spec soci §3.1).
 */
class TipoSocioPolicy
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

    public function update(Utente $utente, TipoSocio $tipoSocio): bool
    {
        return $this->allowedConfig($utente);
    }

    public function delete(Utente $utente, TipoSocio $tipoSocio): bool
    {
        return $this->allowedConfig($utente);
    }
}
