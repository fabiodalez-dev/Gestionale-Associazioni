<?php

namespace App\Policies;

use App\Models\BilancioSociale;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;
use App\Support\PerimetroSedi;

/**
 * Bilancio sociale (issue #15): famiglia di permessi documenti.* (il
 * bilancio finisce tra i documenti pubblicati in Trasparenza); senza
 * permessi seedati solo gli admin dell'associazione. Lo genera e lo
 * modifica solo chi vede tutte le sedi: i dati sono di tutta l'associazione
 * e uno staff limitato a una sede ne calcolerebbe una parte.
 */
class BilancioSocialePolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK = [Utente::RUOLO_ADMIN_ASSOCIAZIONE];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'documenti.view', self::FALLBACK);
    }

    public function view(Utente $utente, BilancioSociale $bilancio): bool
    {
        return $this->allowed($utente, 'documenti.view', self::FALLBACK)
            && $utente->associazione_id === $bilancio->associazione_id;
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'documenti.create', self::FALLBACK)
            && PerimetroSedi::sediDi($utente) === null;
    }

    public function update(Utente $utente, BilancioSociale $bilancio): bool
    {
        return $this->allowed($utente, 'documenti.update', self::FALLBACK)
            && $utente->associazione_id === $bilancio->associazione_id
            && PerimetroSedi::sediDi($utente) === null;
    }
}
