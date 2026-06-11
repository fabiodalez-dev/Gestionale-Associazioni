<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Utente;

/**
 * Regole pagina "Chiavi API":
 * - accesso con permesso spatie api.view (operatore escluso dal RolesSeeder);
 * - creazione: super_admin (anche globale) o utente con associazione;
 * - toggle/delete: super_admin su qualunque chiave; gli altri solo sulle
 *   chiavi della propria associazione (mai globali né di altri tenant).
 */
class ApiKeyPolicy
{
    use \App\Policies\Concerns\AuthorizesModulePermissions;

    public function viewAny(Utente $utente): bool
    {
        return $this->allowed($utente, 'api.view', ['admin_associazione']);
    }

    public function create(Utente $utente): bool
    {
        return $utente->isSuperAdmin()
            || ! empty($utente->associazione_id)
            || session('associazione_id') !== null;
    }

    public function update(Utente $utente, ApiKey $apiKey): bool
    {
        return $this->gestibile($utente, $apiKey);
    }

    public function delete(Utente $utente, ApiKey $apiKey): bool
    {
        return $this->gestibile($utente, $apiKey);
    }

    private function gestibile(Utente $utente, ApiKey $apiKey): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        $tenant = $utente->associazione_id ?: session('associazione_id');

        return $apiKey->associazione_id !== null
            && $tenant !== null
            && $apiKey->associazione_id === $tenant;
    }
}
