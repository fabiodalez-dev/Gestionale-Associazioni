<?php

namespace App\Policies;

use App\Models\Annuncio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Bacheca annunci (f.7 piano Gestione 2): la famiglia di permessi è quella
 * delle comunicazioni (email.*) — consultazione con email.view (anche
 * operatore), gestione con email.create/update/delete (admin del tenant).
 */
class AnnuncioPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK_VIEW = ['admin_associazione', 'operatore'];

    private const FALLBACK_MANAGE = ['admin_associazione'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'comunicazione.view', self::FALLBACK_VIEW);
    }

    public function view(Utente $utente, Annuncio $annuncio): bool
    {
        return $this->allowed($utente, 'comunicazione.view', self::FALLBACK_VIEW)
            && $this->stessoTenant($utente, $annuncio);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'comunicazione.create', self::FALLBACK_MANAGE);
    }

    public function update(Utente $utente, Annuncio $annuncio): bool
    {
        return $this->allowed($utente, 'comunicazione.update', self::FALLBACK_MANAGE)
            && $this->stessoTenant($utente, $annuncio);
    }

    public function delete(Utente $utente, Annuncio $annuncio): bool
    {
        return $this->allowed($utente, 'comunicazione.delete', self::FALLBACK_MANAGE)
            && $this->stessoTenant($utente, $annuncio);
    }

    private function stessoTenant(Utente $utente, Annuncio $annuncio): bool
    {
        return $utente->associazione_id !== null
            && $utente->associazione_id === $annuncio->associazione_id;
    }
}
