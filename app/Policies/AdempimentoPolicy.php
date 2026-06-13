<?php

namespace App\Policies;

use App\Models\Adempimento;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Adempimenti ricorrenti (f.20 del piano "Organizzazione 3"): la checklist
 * vive nello Scadenzario (consultazione con scadenze.view), mentre la
 * gestione dell'anagrafica degli adempimenti — materia di governance —
 * segue la famiglia di permessi `struttura.*`, riservata agli admin.
 * Lo spunto/de-spunto della checklist annuale richiede scadenze.update.
 */
class AdempimentoPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = [Utente::RUOLO_ADMIN_ASSOCIAZIONE];

    private const FALLBACK_CHECKLIST = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'scadenze.view', self::FALLBACK_CHECKLIST);
    }

    public function view(Utente $utente, Adempimento $adempimento): bool
    {
        return $this->allowed($utente, 'scadenze.view', self::FALLBACK_CHECKLIST)
            && $this->stessoTenant($utente, $adempimento);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.create', self::FALLBACK);
    }

    public function update(Utente $utente, Adempimento $adempimento): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK)
            && $this->stessoTenant($utente, $adempimento);
    }

    public function delete(Utente $utente, Adempimento $adempimento): bool
    {
        return $this->allowed($utente, 'struttura.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $adempimento);
    }

    /**
     * Spunta/de-spunta la voce di checklist annuale (f.20): basta il
     * permesso operativo scadenze.update, condiviso con l'operatore.
     */
    public function toggleChecklist(Utente $utente, Adempimento $adempimento): bool
    {
        return $this->allowed($utente, 'scadenze.update', self::FALLBACK_CHECKLIST)
            && $this->stessoTenant($utente, $adempimento);
    }

    private function stessoTenant(Utente $utente, Adempimento $adempimento): bool
    {
        return $utente->associazione_id === $adempimento->associazione_id;
    }
}
