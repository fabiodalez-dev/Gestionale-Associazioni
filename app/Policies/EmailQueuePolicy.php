<?php

namespace App\Policies;

use App\Models\EmailQueue;
use App\Models\Utente;

/**
 * Coda email: consultazione con email.view; invio comunicazioni e gestione
 * coda (elabora/annulla/riprova) con email.create.
 */
class EmailQueuePolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('email.view');
    }

    /** Invio comunicazioni massive (accodamento) */
    public function send(Utente $utente): bool
    {
        return $utente->can('email.create');
    }

    /** Elaborazione manuale della coda */
    public function process(Utente $utente): bool
    {
        return $utente->can('email.create');
    }

    public function cancel(Utente $utente, EmailQueue $item): bool
    {
        return $utente->can('email.create');
    }

    public function retry(Utente $utente, EmailQueue $item): bool
    {
        return $utente->can('email.create');
    }
}
