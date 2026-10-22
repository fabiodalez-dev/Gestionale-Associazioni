<?php

namespace App\Policies;

use App\Models\Movimento;
use App\Models\Utente;

/**
 * Autorizzazioni prima nota (f.13 del piano "Gestione 2"): come da
 * regole operative usa quote.* come famiglia economica. Il vincolo di
 * tenant è garantito dal global scope BelongsToAssociazione.
 */
class MovimentoPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('economia.view');
    }

    public function view(Utente $utente, Movimento $movimento): bool
    {
        return $utente->can('economia.view');
    }

    public function create(Utente $utente): bool
    {
        return $utente->can('economia.create');
    }

    public function update(Utente $utente, Movimento $movimento): bool
    {
        return $utente->can('economia.update');
    }

    public function delete(Utente $utente, Movimento $movimento): bool
    {
        return $utente->can('economia.delete');
    }
}
