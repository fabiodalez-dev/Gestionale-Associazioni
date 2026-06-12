<?php

namespace App\Policies;

use App\Models\AttivitaAdmin;
use App\Models\Utente;

/**
 * Attività/promemoria admin (f.4 del piano "Gestione 2"): strumento
 * operativo interno aperto a tutti i ruoli amministrativi del tenant
 * (come la dashboard). L'isolamento per tenant è garantito dal global
 * scope di AttivitaAdmin (id di altri tenant → 404 sul binding).
 */
class AttivitaAdminPolicy
{
    public function viewAny(Utente $utente): bool
    {
        return $this->ruoloAmmesso($utente);
    }

    public function create(Utente $utente): bool
    {
        return $this->ruoloAmmesso($utente);
    }

    public function update(Utente $utente, AttivitaAdmin $attivita): bool
    {
        return $this->ruoloAmmesso($utente);
    }

    public function delete(Utente $utente, AttivitaAdmin $attivita): bool
    {
        return $this->ruoloAmmesso($utente);
    }

    private function ruoloAmmesso(Utente $utente): bool
    {
        return in_array($utente->ruolo, Utente::RUOLI, true);
    }
}
