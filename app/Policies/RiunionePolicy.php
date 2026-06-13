<?php

namespace App\Policies;

use App\Models\Riunione;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Riunioni del direttivo (f.17-18 del piano "Organizzazione 3"): la
 * gestione delle riunioni è materia di governance e segue la famiglia
 * di permessi `struttura.*` (come progetti e contatti), riservata agli
 * admin dell'associazione (l'operatore non ha la famiglia struttura).
 */
class RiunionePolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = [Utente::RUOLO_ADMIN_ASSOCIAZIONE];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.view', self::FALLBACK);
    }

    public function view(Utente $utente, Riunione $riunione): bool
    {
        return $this->allowed($utente, 'struttura.view', self::FALLBACK)
            && $this->stessoTenant($utente, $riunione);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.create', self::FALLBACK);
    }

    public function update(Utente $utente, Riunione $riunione): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK)
            && $this->stessoTenant($utente, $riunione);
    }

    public function delete(Utente $utente, Riunione $riunione): bool
    {
        return $this->allowed($utente, 'struttura.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $riunione);
    }

    private function stessoTenant(Utente $utente, Riunione $riunione): bool
    {
        return $utente->associazione_id === $riunione->associazione_id;
    }
}
