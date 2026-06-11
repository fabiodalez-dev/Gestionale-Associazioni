<?php

namespace App\Policies;

use App\Models\Tessera;
use App\Models\Utente;

/**
 * Policy del modulo tessere: abilità mappate sui permessi spatie
 * (tessere.view / create / update / delete). Il super_admin bypassa tutto.
 * Le abilità sul singolo modello verificano anche l'appartenenza al tenant
 * corrente (difesa in profondità oltre al global scope).
 */
class TesseraPolicy
{
    public function before(Utente $utente, string $ability): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('tessere.view');
    }

    public function view(Utente $utente, Tessera $tessera): bool
    {
        return $utente->can('tessere.view') && $this->sameTenant($utente, $tessera);
    }

    public function create(Utente $utente): bool
    {
        return $utente->can('tessere.create');
    }

    public function update(Utente $utente, Tessera $tessera): bool
    {
        return $utente->can('tessere.update') && $this->sameTenant($utente, $tessera);
    }

    public function delete(Utente $utente, Tessera $tessera): bool
    {
        return $utente->can('tessere.delete') && $this->sameTenant($utente, $tessera);
    }

    private function sameTenant(Utente $utente, Tessera $tessera): bool
    {
        return $utente->associazione_id === null
            || $tessera->associazione_id === $utente->associazione_id;
    }
}
