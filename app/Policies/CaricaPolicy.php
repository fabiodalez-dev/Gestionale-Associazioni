<?php

namespace App\Policies;

use App\Models\Carica;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Organi sociali (f.16 del piano vita sociale): la consultazione delle
 * cariche segue soci.view; la gestione (nomina, modifica, rimozione di
 * un mandato) è materia di governance e resta riservata agli admin
 * dell'associazione (stesso criterio delle pagine di configurazione).
 */
class CaricaPolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function view(Utente $utente, Carica $carica): bool
    {
        return $this->viewAny($utente) && $this->stessoTenant($utente, $carica);
    }

    public function create(Utente $utente): bool
    {
        return $utente->isAdminAssociazione();
    }

    public function update(Utente $utente, Carica $carica): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $carica);
    }

    public function delete(Utente $utente, Carica $carica): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $carica);
    }

    private function stessoTenant(Utente $utente, Carica $carica): bool
    {
        return $utente->associazione_id === $carica->associazione_id;
    }
}
