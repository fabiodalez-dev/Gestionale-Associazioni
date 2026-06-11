<?php

namespace App\Policies;

use App\Models\Scadenza;
use App\Models\Utente;

/**
 * Matrice ruoli (spec §5): CRUD scadenze + completamento per tutti
 * gli utenti del tenant (super_admin, admin_associazione, operatore).
 */
class ScadenzaPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return true;
    }

    public function view(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->stessoTenant($utente, $scadenza);
    }

    public function create(Utente $utente): bool
    {
        return true;
    }

    public function update(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->stessoTenant($utente, $scadenza);
    }

    public function complete(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->stessoTenant($utente, $scadenza);
    }

    public function delete(Utente $utente, Scadenza $scadenza): bool
    {
        return $this->stessoTenant($utente, $scadenza);
    }

    private function stessoTenant(Utente $utente, Scadenza $scadenza): bool
    {
        return $utente->associazione_id === $scadenza->associazione_id;
    }
}
