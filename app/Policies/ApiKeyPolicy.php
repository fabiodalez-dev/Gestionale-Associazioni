<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Utente;
use App\Policies\Concerns\AuthorizesModulePermissions;

/**
 * Regole pagina "Chiavi API":
 * - accesso con permesso spatie api.view (operatore escluso dal RolesSeeder);
 * - creazione: super_admin (anche globale) o utente con associazione e
 *   permesso api.create (l'operatore, escluso dalla pagina, non si crea
 *   chiavi con più poteri dei propri);
 * - toggle/delete: super_admin su qualunque chiave; gli altri, con permesso
 *   api.update/api.delete, solo sulle chiavi della propria associazione
 *   (mai globali né di altri tenant).
 */
class ApiKeyPolicy
{
    use AuthorizesModulePermissions;

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'api.view', ['admin_associazione']);
    }

    /**
     * Creazione di chiavi API: super admin, oppure chi ha il permesso in un
     * tenant definito.
     */
    public function create(Utente $utente): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        // Solo la propria associazione: la sessione non vale per i non super admin
        return ! empty($utente->associazione_id)
            && $this->allowed($utente, 'api.create', ['admin_associazione']);
    }

    /**
     * Modifica di una chiave API: super admin, o del proprio tenant con il
     * permesso api.update.
     */
    public function update(Utente $utente, ApiKey $apiKey): bool
    {
        return $this->gestibile($utente, $apiKey)
            && ($utente->isSuperAdmin() || $this->allowed($utente, 'api.update', ['admin_associazione']));
    }

    /**
     * Eliminazione di una chiave API: super admin, o del proprio tenant con il
     * permesso api.delete.
     */
    public function delete(Utente $utente, ApiKey $apiKey): bool
    {
        return $this->gestibile($utente, $apiKey)
            && ($utente->isSuperAdmin() || $this->allowed($utente, 'api.delete', ['admin_associazione']));
    }

    private function gestibile(Utente $utente, ApiKey $apiKey): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        // Solo la propria associazione (mai il tenant in sessione)
        $tenant = $utente->associazione_id ?: null;

        return $apiKey->associazione_id !== null
            && $tenant !== null
            && $apiKey->associazione_id === $tenant;
    }
}
