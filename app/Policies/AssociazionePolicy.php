<?php

namespace App\Policies;

use App\Models\Associazione;
use App\Models\Utente;

/**
 * CRUD associazioni: esclusiva super_admin (spec struttura §1, §8).
 * manageSettings: configurazioni del tenant (super_admin con tenant selezionato
 * o admin_associazione sul proprio tenant) — corregge la lacuna legacy.
 */
class AssociazionePolicy
{
    public function viewAny(Utente $utente): bool
    {
        return $utente->isSuperAdmin();
    }

    public function view(Utente $utente, Associazione $associazione): bool
    {
        return $utente->isSuperAdmin();
    }

    public function create(Utente $utente): bool
    {
        return $utente->isSuperAdmin();
    }

    public function update(Utente $utente, Associazione $associazione): bool
    {
        return $utente->isSuperAdmin();
    }

    public function delete(Utente $utente, Associazione $associazione): bool
    {
        return $utente->isSuperAdmin();
    }

    /**
     * Configurazioni tenant (sedi, anagrafica, tesseramento, template,
     * privacy, campi obbligatori, pagamenti). L'operatore è escluso.
     */
    public function manageSettings(Utente $utente, Associazione $associazione): bool
    {
        if ($utente->isSuperAdmin()) {
            // Il tenant selezionato è garantito dal middleware 'tenant'
            return true;
        }

        return $utente->isAdminAssociazione()
            && $utente->associazione_id === $associazione->id;
    }
}
