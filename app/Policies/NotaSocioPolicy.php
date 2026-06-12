<?php

namespace App\Policies;

use App\Models\NotaSocio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Note interne sul socio (f.3 del piano "Gestione 2"): visibili e gestibili
 * solo dagli admin del tenant (mai dai soci). Il tenant è garantito dal
 * global scope; la creazione/modifica segue la famiglia di permessi soci.
 */
class NotaSocioPolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI);
    }

    public function update(Utente $utente, NotaSocio $nota): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI);
    }

    public function delete(Utente $utente, NotaSocio $nota): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI);
    }
}
