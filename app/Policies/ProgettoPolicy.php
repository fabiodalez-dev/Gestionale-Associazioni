<?php

namespace App\Policies;

use App\Models\Progetto;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy del modulo progetti (f.12–f.16 del piano "Organizzazione 3").
 * Le abilità sono mappate sui permessi spatie della famiglia struttura.*
 * (regola operativa: "struttura.* per progetti/riunioni/contatti"), con
 * fallback al ruolo admin_associazione quando i permessi non sono seedati.
 * L'operatore NON ha permessi struttura (matrice ruoli legacy).
 * Il vincolo di tenant è ridondato esplicitamente: un progetto di altro
 * tenant non viene comunque risolto dal route binding (global scope).
 */
class ProgettoPolicy
{
    use AuthorizesModulePermissions;

    /** @var list<string> */
    private const FALLBACK = ['admin_associazione'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'progetti.view', self::FALLBACK);
    }

    public function view(Utente $utente, Progetto $progetto): bool
    {
        return $this->allowed($utente, 'progetti.view', self::FALLBACK)
            && $this->stessoTenant($utente, $progetto);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'progetti.create', self::FALLBACK);
    }

    public function update(Utente $utente, Progetto $progetto): bool
    {
        return $this->allowed($utente, 'progetti.update', self::FALLBACK)
            && $this->stessoTenant($utente, $progetto);
    }

    public function delete(Utente $utente, Progetto $progetto): bool
    {
        return $this->allowed($utente, 'progetti.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $progetto);
    }

    private function stessoTenant(Utente $utente, Progetto $progetto): bool
    {
        return $utente->associazione_id === $progetto->associazione_id;
    }
}
