<?php

namespace App\Policies;

use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Newsletter da annunci (f.8 piano Gestione 2): storico consultabile con
 * email.view; composizione/anteprima/invio con email.create (come l'invio
 * delle comunicazioni massive — EmailQueuePolicy::send).
 */
class NewsletterInviataPolicy
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

    /** Composizione, anteprima e invio della newsletter */
    public function send(Utente $utente): bool
    {
        return $this->allowed($utente, 'email.create', self::FALLBACK_MANAGE);
    }
}
