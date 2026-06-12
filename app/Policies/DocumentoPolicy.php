<?php

namespace App\Policies;

use App\Models\Documento;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Policy del modulo documenti: abilità mappate sui permessi spatie
 * (documenti.view / create / delete). L'operatore ha view+create;
 * eliminazione/cestino richiedono documenti.delete (admin_associazione).
 * Le abilità sul singolo modello verificano anche l'appartenenza al tenant.
 */
class DocumentoPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK_VIEW = ['admin_associazione', 'operatore'];

    private const FALLBACK_ADMIN = ['admin_associazione'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'documenti.view', self::FALLBACK_VIEW);
    }

    public function view(Utente $utente, Documento $documento): bool
    {
        return $this->allowed($utente, 'documenti.view', self::FALLBACK_VIEW)
            && $this->stessoTenant($utente, $documento);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'documenti.create', self::FALLBACK_VIEW);
    }

    /**
     * Pubblicazione/rimozione del documento dalla pagina Trasparenza
     * (addendum): richiede documenti.update (fallback admin del tenant).
     */
    public function update(Utente $utente, Documento $documento): bool
    {
        return $this->allowed($utente, 'documenti.update', self::FALLBACK_ADMIN)
            && $this->stessoTenant($utente, $documento);
    }

    public function delete(Utente $utente, Documento $documento): bool
    {
        return $this->allowed($utente, 'documenti.delete', self::FALLBACK_ADMIN)
            && $this->stessoTenant($utente, $documento);
    }

    public function restore(Utente $utente, Documento $documento): bool
    {
        return $this->allowed($utente, 'documenti.delete', self::FALLBACK_ADMIN)
            && $this->stessoTenant($utente, $documento);
    }

    public function forceDelete(Utente $utente, Documento $documento): bool
    {
        return $this->allowed($utente, 'documenti.delete', self::FALLBACK_ADMIN)
            && $this->stessoTenant($utente, $documento);
    }

    private function stessoTenant(Utente $utente, Documento $documento): bool
    {
        return $utente->associazione_id === $documento->associazione_id;
    }
}
