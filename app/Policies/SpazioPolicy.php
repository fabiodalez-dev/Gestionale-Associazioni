<?php

namespace App\Policies;

use App\Models\Spazio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;
use App\Support\PerimetroSedi;

/**
 * Policy spazi (f.7 piano "Organizzazione 3"): abilità mappate sui permessi
 * spatie della famiglia eventi.* (come da Regole operative del piano), con
 * fallback per ruolo quando i permessi non sono seedati. Il super_admin
 * bypassa tutto; le abilità sul modello verificano anche il tenant.
 */
class SpazioPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'spazi.view', self::FALLBACK);
    }

    public function view(Utente $utente, Spazio $spazio): bool
    {
        return $this->allowed($utente, 'spazi.view', self::FALLBACK)
            && $this->stessoTenant($utente, $spazio);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'spazi.create', self::FALLBACK);
    }

    public function update(Utente $utente, Spazio $spazio): bool
    {
        return $this->allowed($utente, 'spazi.update', self::FALLBACK)
            && $this->stessoTenant($utente, $spazio)
            && PerimetroSedi::puoModificare($utente, $spazio);
    }

    public function delete(Utente $utente, Spazio $spazio): bool
    {
        return $this->allowed($utente, 'spazi.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $spazio)
            && PerimetroSedi::puoModificare($utente, $spazio);
    }

    private function stessoTenant(Utente $utente, Spazio $spazio): bool
    {
        return $utente->associazione_id === $spazio->associazione_id;
    }
}
