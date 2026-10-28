<?php

namespace App\Policies;

use App\Models\ManualePagina;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Manuale operativo (issue #12): famiglia di permessi manuale.* (lettura per
 * tutto lo staff, scrittura per segretario, presidente e admin). Senza
 * permessi seedati: lettura anche agli operatori, scrittura agli admin.
 */
class ManualePaginaPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK_LETTURA = [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE];

    private const FALLBACK_SCRITTURA = [Utente::RUOLO_ADMIN_ASSOCIAZIONE];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'manuale.view', self::FALLBACK_LETTURA);
    }

    public function view(Utente $utente, ManualePagina $pagina): bool
    {
        return $this->allowed($utente, 'manuale.view', self::FALLBACK_LETTURA)
            && $this->stessoTenant($utente, $pagina);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'manuale.create', self::FALLBACK_SCRITTURA);
    }

    public function update(Utente $utente, ManualePagina $pagina): bool
    {
        return $this->allowed($utente, 'manuale.update', self::FALLBACK_SCRITTURA)
            && $this->stessoTenant($utente, $pagina);
    }

    public function delete(Utente $utente, ManualePagina $pagina): bool
    {
        return $this->allowed($utente, 'manuale.delete', self::FALLBACK_SCRITTURA)
            && $this->stessoTenant($utente, $pagina);
    }

    private function stessoTenant(Utente $utente, ManualePagina $pagina): bool
    {
        return $utente->associazione_id === $pagina->associazione_id;
    }
}
