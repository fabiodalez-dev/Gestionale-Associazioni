<?php

namespace App\Policies;

use App\Models\Documento;
use App\Models\Utente;

/**
 * Matrice ruoli (spec §5): tutti gli utenti del tenant vedono e caricano;
 * eliminazione/cestino riservati a admin_associazione e super_admin.
 * Lo scoping tenant hard è garantito dal global scope BelongsToAssociazione;
 * la policy aggiunge il controllo esplicito di appartenenza.
 */
class DocumentoPolicy
{
    public function before(Utente $utente): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(Utente $utente): bool
    {
        return true;
    }

    public function view(Utente $utente, Documento $documento): bool
    {
        return $this->stessoTenant($utente, $documento);
    }

    public function create(Utente $utente): bool
    {
        return true;
    }

    public function delete(Utente $utente, Documento $documento): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $documento);
    }

    public function restore(Utente $utente, Documento $documento): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $documento);
    }

    public function forceDelete(Utente $utente, Documento $documento): bool
    {
        return $utente->isAdminAssociazione() && $this->stessoTenant($utente, $documento);
    }

    private function stessoTenant(Utente $utente, Documento $documento): bool
    {
        return $utente->associazione_id === $documento->associazione_id;
    }
}
