<?php

namespace App\Policies;

use App\Models\Associazione;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * CRUD associazioni: esclusiva super_admin (spec struttura §1, §8).
 * manageSettings: configurazioni del tenant (super_admin con tenant selezionato
 * o admin_associazione sul proprio tenant) — corregge la lacuna legacy.
 * Modulo di iscrizione (PIANO-ISCRIZIONI §Rotte/Admin): permessi spatie
 * struttura.view/struttura.update con fallback admin_associazione.
 */
class AssociazionePolicy
{
    use AuthorizesModulePermissions;

    /** L'operatore non ha permessi struttura (matrice ruoli legacy). */
    private const FALLBACK_STRUTTURA = ['admin_associazione'];

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

    /**
     * Pagina "Modulo di iscrizione": struttura.view sul proprio tenant
     * (super_admin: tenant selezionato dal middleware 'tenant').
     */
    public function viewModuloIscrizione(Utente $utente, Associazione $associazione): bool
    {
        return $utente->isSuperAdmin()
            || ($this->allowed($utente, 'struttura.view', self::FALLBACK_STRUTTURA)
                && $utente->associazione_id === $associazione->id);
    }

    /**
     * Salvataggio del modulo di iscrizione: struttura.update sul proprio tenant.
     */
    public function updateModuloIscrizione(Utente $utente, Associazione $associazione): bool
    {
        return $utente->isSuperAdmin()
            || ($this->allowed($utente, 'struttura.update', self::FALLBACK_STRUTTURA)
                && $utente->associazione_id === $associazione->id);
    }
}
