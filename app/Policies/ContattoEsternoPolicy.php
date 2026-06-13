<?php

namespace App\Policies;

use App\Models\ContattoEsterno;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Mini-CRM contatti esterni (f.21 del piano "Organizzazione 3"): mappato
 * sulla famiglia di permessi struttura.* (relazioni esterne dell'ente).
 * Il super_admin bypassa tutto; le abilità sul singolo modello verificano
 * anche l'appartenenza al tenant (difesa in profondità oltre al global
 * scope BelongsToAssociazione).
 */
class ContattoEsternoPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.view', self::FALLBACK);
    }

    public function view(Utente $utente, ContattoEsterno $contatto): bool
    {
        return $this->allowed($utente, 'struttura.view', self::FALLBACK)
            && $this->stessoTenant($utente, $contatto);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK);
    }

    public function update(Utente $utente, ContattoEsterno $contatto): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK)
            && $this->stessoTenant($utente, $contatto);
    }

    public function delete(Utente $utente, ContattoEsterno $contatto): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK)
            && $this->stessoTenant($utente, $contatto);
    }

    private function stessoTenant(Utente $utente, ContattoEsterno $contatto): bool
    {
        return $utente->associazione_id === $contatto->associazione_id;
    }
}
