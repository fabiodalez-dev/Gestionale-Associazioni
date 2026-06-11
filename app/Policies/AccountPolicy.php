<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Autorizzazioni sugli account del frontend pubblico per entrambe le guard:
 * - Account: SOLO se stesso (area riservata: profilo, preferenze, privacy);
 * - Utente (admin): account del proprio tenant (super_admin: tutti),
 *   in sola lettura/gestione amministrativa (mai export/anonimizzazione
 *   self-service, che restano azioni del titolare dei dati).
 *
 * Le pagine admin "Account frontend" sono gestione della struttura:
 * lista/dettaglio con il permesso spatie struttura.view, azioni
 * (collega-socio, promuovi-socio, disattiva/riattiva) con struttura.update
 * (fallback admin_associazione: l'operatore non ha permessi struttura).
 */
class AccountPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione'];

    /**
     * Lista admin degli account del tenant (solo Utente).
     */
    public function viewAny(Account|Utente $user): bool
    {
        return $user instanceof Utente
            && $this->allowed($user, 'struttura.view', self::FALLBACK);
    }

    public function view(Account|Utente $user, Account $account): bool
    {
        if ($user instanceof Utente) {
            return $user->isSuperAdmin()
                || ($this->allowed($user, 'struttura.view', self::FALLBACK)
                    && $user->associazione_id === $account->associazione_id);
        }

        return $user->id === $account->id;
    }

    /**
     * Azioni amministrative sull'account (collega-socio, promuovi-socio,
     * disattiva/riattiva): solo Utente con struttura.update sul proprio tenant.
     */
    public function gestisci(Account|Utente $user, Account $account): bool
    {
        if (! $user instanceof Utente) {
            return false;
        }

        return $user->isSuperAdmin()
            || ($this->allowed($user, 'struttura.update', self::FALLBACK)
                && $user->associazione_id === $account->associazione_id);
    }

    /**
     * Aggiornamento self-service di anagrafica, password, email e preferenze.
     */
    public function update(Account|Utente $user, Account $account): bool
    {
        return $user instanceof Account && $user->id === $account->id;
    }

    /**
     * Export GDPR dei propri dati: solo il titolare.
     */
    public function export(Account|Utente $user, Account $account): bool
    {
        return $user instanceof Account && $user->id === $account->id;
    }

    /**
     * Cancellazione GDPR (anonimizzazione): solo il titolare.
     */
    public function anonimizza(Account|Utente $user, Account $account): bool
    {
        return $user instanceof Account && $user->id === $account->id;
    }
}
