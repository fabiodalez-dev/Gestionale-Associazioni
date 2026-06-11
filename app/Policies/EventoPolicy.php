<?php

namespace App\Policies;

use App\Models\Evento;
use App\Models\Utente;

/**
 * Policy del modulo eventi.
 *
 * Parità con il legacy: nessuna differenziazione di ruolo — qualunque utente
 * autenticato dell'area amministrativa può gestire eventi e partecipanti del
 * proprio tenant (l'isolamento è garantito dal global scope BelongsToAssociazione
 * e dal middleware 'tenant'). Il super_admin opera sull'associazione selezionata.
 */
class EventoPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return true;
    }

    public function view(Utente $utente, Evento $evento): bool
    {
        return true;
    }

    public function create(Utente $utente): bool
    {
        return true;
    }

    public function update(Utente $utente, Evento $evento): bool
    {
        return true;
    }

    public function delete(Utente $utente, Evento $evento): bool
    {
        return true;
    }
}
