<?php

namespace App\Policies;

use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Audit accessi (f.25 del piano "Gestione 2"): pagina di sola consultazione,
 * riservata agli amministratori — stesso permesso della sezione
 * Amministrazione in sidebar (struttura.view). L'operatore non vi accede.
 */
class AccessoPolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE];

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.view', self::RUOLI);
    }
}
