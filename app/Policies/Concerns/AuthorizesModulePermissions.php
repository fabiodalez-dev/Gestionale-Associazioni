<?php

namespace App\Policies\Concerns;

use App\Models\Utente;

/**
 * Autorizzazione delle Policy di modulo:
 * - super_admin: sempre consentito;
 * - permesso spatie granulare (es. 'soci.view') se assegnato (miglioramento 3);
 * - fallback sui ruoli legacy quando i permessi non sono stati seedati
 *   (es. ambienti di test senza RolesSeeder), per fedeltà alla matrice ruoli del legacy.
 */
trait AuthorizesModulePermissions
{
    /**
     * @param  list<string>  $ruoliFallback
     */
    protected function allowed(Utente $utente, string $permission, array $ruoliFallback): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        try {
            if ($utente->hasPermissionTo($permission)) {
                return true;
            }
        } catch (\Throwable) {
            // permesso non definito: si ricade sul fallback per ruolo
            return in_array($utente->ruolo, $ruoliFallback, true);
        }

        return false;
    }

    /**
     * Pagine di configurazione: solo admin_associazione e super_admin (spec soci §13).
     */
    protected function allowedConfig(Utente $utente): bool
    {
        return $utente->isSuperAdmin() || $utente->isAdminAssociazione();
    }
}
