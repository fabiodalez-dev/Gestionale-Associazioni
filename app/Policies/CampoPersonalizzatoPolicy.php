<?php

namespace App\Policies;

use App\Models\CampoPersonalizzato;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Configurazione campi personalizzati: solo admin_associazione e super_admin (spec soci §13).
 */
class CampoPersonalizzatoPolicy
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

    public function update(Utente $utente, CampoPersonalizzato $campoPersonalizzato): bool
    {
        return $this->allowedConfig($utente);
    }

    public function delete(Utente $utente, CampoPersonalizzato $campoPersonalizzato): bool
    {
        return $this->allowedConfig($utente);
    }
}
