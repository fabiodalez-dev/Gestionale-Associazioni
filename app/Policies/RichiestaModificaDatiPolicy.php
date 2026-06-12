<?php

namespace App\Policies;

use App\Models\RichiestaModificaDati;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Richieste di modifica dei dati anagrafici (f.19 — piano "Gestione 2"):
 * la lista segue soci.view, approvazione e rifiuto seguono soci.update
 * (la gestione modifica l'anagrafica del socio). Il vincolo tenant è
 * garantito dal global scope BelongsToAssociazione (richiesta di un
 * altro tenant = 404 sul binding).
 */
class RichiestaModificaDatiPolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    public function view(Utente $utente, RichiestaModificaDati $richiesta): bool
    {
        return $this->allowed($utente, 'soci.view', self::RUOLI);
    }

    /**
     * Approvazione/rifiuto: stessa abilitazione della modifica soci.
     */
    public function gestisci(Utente $utente, RichiestaModificaDati $richiesta): bool
    {
        return $this->allowed($utente, 'soci.update', self::RUOLI);
    }
}
