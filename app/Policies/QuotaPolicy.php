<?php

namespace App\Policies;

use App\Models\Quota;
use App\Models\Utente;

/**
 * Autorizzazioni modulo quote (permessi spatie quote.*).
 * Il vincolo di tenant è garantito dal global scope BelongsToAssociazione
 * (una quota di altro tenant non viene nemmeno risolta dal route binding).
 */
class QuotaPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $utente->can('quote.view');
    }

    public function view(Utente $utente, Quota $quota): bool
    {
        return $utente->can('quote.view');
    }

    public function create(Utente $utente): bool
    {
        return $utente->can('quote.create');
    }

    public function update(Utente $utente, Quota $quota): bool
    {
        return $utente->can('quote.update');
    }

    public function delete(Utente $utente, Quota $quota): bool
    {
        return $utente->can('quote.delete');
    }

    /**
     * Registrazione pagamento manuale e generazione link di pagamento.
     */
    public function registraPagamento(Utente $utente, Quota $quota): bool
    {
        return $utente->can('quote.update');
    }

    /**
     * Configurazione gateway di pagamento (riservata agli admin: permesso struttura).
     */
    public function gestisciImpostazioni(Utente $utente): bool
    {
        return $utente->can('struttura.update');
    }
}
