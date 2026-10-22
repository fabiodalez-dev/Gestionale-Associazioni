<?php

namespace App\Policies;

use App\Models\RichiestaIscrizione;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Richieste di iscrizione socio dal form pubblico (PIANO-ISCRIZIONI §Admin):
 * la lista segue soci.view, approvazione e rifiuto seguono soci.update
 * (la gestione crea/elimina soci, come la pipeline preiscrizioni).
 * Il vincolo tenant è garantito dal global scope BelongsToAssociazione
 * (richiesta di un altro tenant = 404 sul binding).
 */
class RichiestaIscrizionePolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'iscrizioni.view', self::RUOLI);
    }

    public function view(Utente $utente, RichiestaIscrizione $richiesta): bool
    {
        return $this->allowed($utente, 'iscrizioni.view', self::RUOLI);
    }

    /**
     * Approvazione/rifiuto: stessa abilitazione della modifica soci.
     */
    public function gestisci(Utente $utente, RichiestaIscrizione $richiesta): bool
    {
        return $this->allowed($utente, 'iscrizioni.update', self::RUOLI);
    }
}
