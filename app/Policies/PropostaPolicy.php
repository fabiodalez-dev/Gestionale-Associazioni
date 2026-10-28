<?php

namespace App\Policies;

use App\Models\Proposta;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Proposte dei soci (issue #10), lato segreteria: famiglia di permessi
 * proposte.* (gestione per segretario e presidente, lettura per i
 * consiglieri), riservata agli admin dell'associazione in assenza di permessi.
 */
class PropostaPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = [Utente::RUOLO_ADMIN_ASSOCIAZIONE];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'proposte.view', self::FALLBACK);
    }

    public function view(Utente $utente, Proposta $proposta): bool
    {
        return $this->allowed($utente, 'proposte.view', self::FALLBACK)
            && $this->stessoTenant($utente, $proposta);
    }

    public function update(Utente $utente, Proposta $proposta): bool
    {
        return $this->allowed($utente, 'proposte.update', self::FALLBACK)
            && $this->stessoTenant($utente, $proposta);
    }

    private function stessoTenant(Utente $utente, Proposta $proposta): bool
    {
        return $utente->associazione_id === $proposta->associazione_id;
    }
}
