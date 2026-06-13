<?php

namespace App\Policies;

use App\Models\Sponsor;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Sponsor & partner (f.22 del piano "Organizzazione 3"): mappato sulla
 * famiglia di permessi struttura.* (relazioni esterne dell'ente).
 * Il super_admin bypassa tutto; le abilità sul singolo modello verificano
 * anche l'appartenenza al tenant.
 */
class SponsorPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.view', self::FALLBACK);
    }

    public function view(Utente $utente, Sponsor $sponsor): bool
    {
        return $this->allowed($utente, 'struttura.view', self::FALLBACK)
            && $this->stessoTenant($utente, $sponsor);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK);
    }

    public function update(Utente $utente, Sponsor $sponsor): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK)
            && $this->stessoTenant($utente, $sponsor);
    }

    public function delete(Utente $utente, Sponsor $sponsor): bool
    {
        return $this->allowed($utente, 'struttura.update', self::FALLBACK)
            && $this->stessoTenant($utente, $sponsor);
    }

    private function stessoTenant(Utente $utente, Sponsor $sponsor): bool
    {
        return $utente->associazione_id === $sponsor->associazione_id;
    }
}
