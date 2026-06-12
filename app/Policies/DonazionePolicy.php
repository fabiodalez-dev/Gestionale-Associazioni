<?php

namespace App\Policies;

use App\Models\Utente;

/**
 * Autorizzazioni elenco donazioni admin (f.15 del piano "Gestione 2"):
 * famiglia economica quote.* come per la prima nota. Le donazioni
 * nascono SOLO dalla pagina pubblica /a/{slug}/dona: in admin sono in
 * sola lettura.
 */
class DonazionePolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('quote.view');
    }
}
