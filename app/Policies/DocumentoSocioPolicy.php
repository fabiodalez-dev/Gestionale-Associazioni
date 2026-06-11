<?php

namespace App\Policies;

use App\Models\DocumentoSocio;
use App\Models\Utente;

/**
 * Stessa matrice dei documenti associazione (spec §5):
 * vedere/caricare per tutti gli utenti del tenant, eliminare solo admin.
 */
class DocumentoSocioPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return true;
    }

    public function view(Utente $utente, DocumentoSocio $documento): bool
    {
        return $this->stessoTenant($utente, $documento);
    }

    public function create(Utente $utente): bool
    {
        return true;
    }

    public function delete(Utente $utente, DocumentoSocio $documento): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $documento);
    }

    public function restore(Utente $utente, DocumentoSocio $documento): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $documento);
    }

    public function forceDelete(Utente $utente, DocumentoSocio $documento): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $documento);
    }

    private function stessoTenant(Utente $utente, DocumentoSocio $documento): bool
    {
        return $utente->associazione_id === $documento->associazione_id;
    }
}
