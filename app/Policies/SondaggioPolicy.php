<?php

namespace App\Policies;

use App\Models\Sondaggio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Sondaggi ai soci (f.11 piano Gestione 2): famiglia permessi delle
 * comunicazioni (email.*) — risultati visibili con email.view, gestione
 * con email.create/update/delete. Il VOTO non passa da questa policy:
 * arriva dalle guard 'socio'/'account' (vedi SondaggiVotoService).
 */
class SondaggioPolicy
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
        return $this->allowed($utente, 'email.view', self::FALLBACK_VIEW);
    }

    public function view(Utente $utente, Sondaggio $sondaggio): bool
    {
        return $this->allowed($utente, 'email.view', self::FALLBACK_VIEW)
            && $this->stessoTenant($utente, $sondaggio);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'email.create', self::FALLBACK_MANAGE);
    }

    public function update(Utente $utente, Sondaggio $sondaggio): bool
    {
        return $this->allowed($utente, 'email.update', self::FALLBACK_MANAGE)
            && $this->stessoTenant($utente, $sondaggio);
    }

    public function delete(Utente $utente, Sondaggio $sondaggio): bool
    {
        return $this->allowed($utente, 'email.delete', self::FALLBACK_MANAGE)
            && $this->stessoTenant($utente, $sondaggio);
    }

    private function stessoTenant(Utente $utente, Sondaggio $sondaggio): bool
    {
        return $utente->associazione_id !== null
            && $utente->associazione_id === $sondaggio->associazione_id;
    }
}
