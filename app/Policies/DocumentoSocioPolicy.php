<?php

namespace App\Policies;

use App\Models\DocumentoSocio;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Stessa matrice dei documenti associazione: permessi spatie documenti.*
 * (view+create per operatore, delete/cestino con documenti.delete).
 */
class DocumentoSocioPolicy
{
    use AuthorizesModulePermissions;

    private const FALLBACK_VIEW = ['admin_associazione', 'operatore'];

    private const FALLBACK_ADMIN = ['admin_associazione'];

    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    /**
     * I documenti del fascicolo sono dati del socio: oltre al permesso del modulo
     * documenti serve l'autorità sul socio stesso (soci.view per leggere,
     * soci.update per aggiungere), come nel tab documenti della scheda socio.
     */
    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'documenti.view', self::FALLBACK_VIEW)
            && $this->allowed($utente, 'soci.view', self::FALLBACK_VIEW);
    }

    public function view(Utente $utente, DocumentoSocio $documento): bool
    {
        return $this->viewAny($utente)
            && $this->stessoTenant($utente, $documento);
    }

    public function create(Utente $utente): bool
    {
        return $this->allowed($utente, 'documenti.create', self::FALLBACK_VIEW)
            && $this->allowed($utente, 'soci.update', self::FALLBACK_VIEW);
    }

    public function delete(Utente $utente, DocumentoSocio $documento): bool
    {
        return $this->allowed($utente, 'documenti.delete', self::FALLBACK_ADMIN)
            && $this->stessoTenant($utente, $documento);
    }

    public function restore(Utente $utente, DocumentoSocio $documento): bool
    {
        return $this->allowed($utente, 'documenti.delete', self::FALLBACK_ADMIN)
            && $this->stessoTenant($utente, $documento);
    }

    public function forceDelete(Utente $utente, DocumentoSocio $documento): bool
    {
        return $this->allowed($utente, 'documenti.delete', self::FALLBACK_ADMIN)
            && $this->stessoTenant($utente, $documento);
    }

    private function stessoTenant(Utente $utente, DocumentoSocio $documento): bool
    {
        return $utente->associazione_id === $documento->associazione_id;
    }
}
