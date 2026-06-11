<?php

namespace App\Policies;

use App\Models\Scadenza;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy del modulo scadenze: abilità mappate sui permessi spatie
 * (scadenze.view / create / update / delete), tutti concessi anche
 * all'operatore dal RolesSeeder. Tenant check sul singolo modello.
 */
class ScadenzaPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'scadenze.view', self::FALLBACK);
    }

    public function view(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->allowed($utente, 'scadenze.view', self::FALLBACK)
            && $this->stessoTenant($utente, $scadenza);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'scadenze.create', self::FALLBACK);
    }

    public function update(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->allowed($utente, 'scadenze.update', self::FALLBACK)
            && $this->stessoTenant($utente, $scadenza);
    }

    public function complete(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->allowed($utente, 'scadenze.update', self::FALLBACK)
            && $this->stessoTenant($utente, $scadenza);
    }

    public function delete(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->allowed($utente, 'scadenze.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $scadenza);
    }

    private function stessoTenant(Utente $utente, Scadenza $scadenza): bool
    {
        return $utente->associazione_id === $scadenza->associazione_id;
    }
}
