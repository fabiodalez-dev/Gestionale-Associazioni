<?php

namespace App\Policies;

use App\Models\PrenotazioneSpazio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy prenotazioni spazi (f.8 piano "Organizzazione 3"): permessi
 * della famiglia eventi.*, fallback per ruolo, verifica del tenant sul
 * modello.
 */
class PrenotazioneSpazioPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = ['admin_associazione', 'operatore'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'spazi.view', self::FALLBACK);
    }

    public function view(Utente $utente, PrenotazioneSpazio $prenotazione): bool
    {
        return $this->allowed($utente, 'spazi.view', self::FALLBACK)
            && $this->stessoTenant($utente, $prenotazione);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'spazi.create', self::FALLBACK);
    }

    public function update(Utente $utente, PrenotazioneSpazio $prenotazione): bool
    {
        return $this->allowed($utente, 'spazi.update', self::FALLBACK)
            && $this->stessoTenant($utente, $prenotazione);
    }

    public function delete(Utente $utente, PrenotazioneSpazio $prenotazione): bool
    {
        return $this->allowed($utente, 'spazi.delete', self::FALLBACK)
            && $this->stessoTenant($utente, $prenotazione);
    }

    private function stessoTenant(Utente $utente, PrenotazioneSpazio $prenotazione): bool
    {
        return $utente->associazione_id === $prenotazione->associazione_id;
    }
}
