<?php

namespace App\Policies;

use App\Models\SavedFilter;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Preset filtri soci: utilizzabili da tutti i ruoli; eliminabili i propri
 * e quelli condivisi (user_id NULL) — spec soci §11.2.
 */
class SavedFilterPolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function delete(Utente $utente, SavedFilter $savedFilter): bool
    {
        if (! $this->allowed($utente, 'soci.view', self::RUOLI)) {
            return false;
        }

        return $savedFilter->user_id === null || $savedFilter->user_id === $utente->id;
    }
}
