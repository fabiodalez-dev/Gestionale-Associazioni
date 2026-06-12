<?php

namespace App\Policies;

use App\Models\Nucleo;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Nuclei familiari (f.20 — piano "Gestione 2"): la vista per nucleo segue
 * soci.view, la gestione (crea/aggiungi/rimuovi/sciogli) segue soci.update
 * perché modifica l'anagrafica dei soci (soci.nucleo_id).
 */
class NucleoPolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function view(Utente $utente, Nucleo $nucleo): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI)
            && $this->stessoTenant($utente, $nucleo);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI);
    }

    public function update(Utente $utente, Nucleo $nucleo): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI)
            && $this->stessoTenant($utente, $nucleo);
    }

    public function delete(Utente $utente, Nucleo $nucleo): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI)
            && $this->stessoTenant($utente, $nucleo);
    }

    private function stessoTenant(Utente $utente, Nucleo $nucleo): bool
    {
        return $utente->isSuperAdmin() || $utente->associazione_id === $nucleo->associazione_id;
    }
}
