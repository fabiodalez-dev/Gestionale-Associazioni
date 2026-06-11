<?php

namespace App\Policies;

use App\Models\EmailLog;
use App\Models\Utente;

/**
 * Storico email: sola consultazione con email.view (log append-only).
 */
class EmailLogPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('email.view');
    }

    public function view(Utente $utente, EmailLog $log): bool
    {
        return $utente->can('email.view');
    }
}
