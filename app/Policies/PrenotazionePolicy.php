<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;

/**
 * Autorizzazioni sulle prenotazioni per entrambe le guard:
 * - Account (frontend pubblico): solo le PROPRIE prenotazioni;
 * - Utente (admin): prenotazioni del proprio tenant (super_admin: tutte).
 *
 * Le regole di STATO (annullabile entro la finestra, senza check-in, ecc.)
 * vivono nella macchina a stati (PrenotazioneService), non qui.
 */
class PrenotazionePolicy
{
    public function view(Account|Utente $user, Prenotazione $prenotazione): bool
    {
        if ($user instanceof Utente) {
            return $user->isSuperAdmin()
                || $user->associazione_id === $prenotazione->associazione_id;
        }

        return $user->id === $prenotazione->account_id;
    }

    /**
     * Un account può prenotare solo eventi della propria associazione.
     */
    public function create(Account|Utente $user, Evento $evento): bool
    {
        return $user instanceof Account
            && $user->associazione_id === $evento->associazione_id;
    }

    public function annulla(Account|Utente $user, Prenotazione $prenotazione): bool
    {
        return $this->view($user, $prenotazione);
    }

    /**
     * Pagina di pagamento, checkout sul gateway ed esito:
     * SOLO il proprietario della prenotazione (mai l'admin).
     */
    public function paga(Account|Utente $user, Prenotazione $prenotazione): bool
    {
        return $user instanceof Account
            && $user->id === $prenotazione->account_id
            && $user->associazione_id === $prenotazione->associazione_id;
    }
}
