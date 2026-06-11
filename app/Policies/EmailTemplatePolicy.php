<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\Utente;

/**
 * Template email: consultabili con email.view, modificabili con email.update.
 * Nota: inasprimento documentato rispetto al legacy (che non distingueva i
 * ruoli): l'operatore (solo email.view) non può modificare i template.
 */
class EmailTemplatePolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('email.view');
    }

    public function view(Utente $utente, EmailTemplate $template): bool
    {
        return $utente->can('email.view');
    }

    public function update(Utente $utente, EmailTemplate $template): bool
    {
        return $utente->can('email.update');
    }
}
