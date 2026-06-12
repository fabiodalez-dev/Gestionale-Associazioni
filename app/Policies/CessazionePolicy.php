<?php

namespace App\Policies;

use App\Models\Cessazione;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Cessazioni formali del rapporto associativo (f.15 del piano vita sociale):
 * la consultazione segue soci.view, la registrazione segue soci.update
 * (è a tutti gli effetti un'azione sul socio, come nel flusso stati).
 * Il registro è append-only: nessuna update/delete esposta.
 */
class CessazionePolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function view(Utente $utente, Cessazione $cessazione): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI)
            && $this->stessoTenant($utente, $cessazione);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI);
    }

    private function stessoTenant(Utente $utente, Cessazione $cessazione): bool
    {
        return $utente->isSuperAdmin()
            || $utente->associazione_id === $cessazione->associazione_id;
    }
}
