<?php

namespace App\Policies;

use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Voci del registro ore volontariato (f.5 del piano "Organizzazione 3").
 *
 * La cancellazione di una voce del registro modifica i dati di volontariato
 * del socio: richiede sempre il permesso esplicito `soci.update`, in modo
 * coerente e indipendente dalla presenza della relazione `socio` (che può
 * risultare null se il socio è stato soft-deleted, dato che il cascade DB
 * non scatta sul soft delete). Evita la deriva di autorizzazione in cui una
 * voce orfana era cancellabile col solo permesso di lettura.
 *
 * Il vincolo tenant è garantito dal global scope BelongsToAssociazione
 * (cross-tenant = 404 sul binding).
 */
class TurnoAssegnazionePolicy
{
    use AuthorizesModulePermissions;

    private const RUOLI = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    public function delete(Utente $utente, TurnoAssegnazione $assegnazione): bool
    {
        return $this->allowed($utente, 'volontari.update', self::RUOLI);
    }
}
