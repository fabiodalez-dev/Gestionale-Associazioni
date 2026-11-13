<?php

namespace App\Policies;

use App\Models\Utente;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Regole sui token personali Sanctum (API v1).
 *
 * - Ogni utente revoca sempre i PROPRI token (comportamento storico della
 *   pagina "Chiavi API").
 * - super_admin: vede e revoca i token di qualsiasi utente.
 * - admin_associazione: vede e revoca i token degli utenti della propria
 *   associazione, mai quelli dei super_admin né di altri tenant.
 * - Tutti gli altri ruoli: solo i propri.
 *
 * Il tenant dell'admin è la sua associazione_id (non quella selezionata in
 * sessione: l'admin_associazione non cambia tenant). Usata direttamente da
 * ApiTokenController, perché il modello è del pacchetto Sanctum.
 */
class PersonalAccessTokenPolicy
{
    /**
     * Accesso alla pagina di gestione dei token altrui.
     */
    public function viewAny(Utente $utente): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        return $utente->isAdminAssociazione() && ! empty($utente->associazione_id);
    }

    public function revoca(Utente $utente, PersonalAccessToken $token): bool
    {
        $proprietario = $token->tokenable;

        if (! $proprietario instanceof Utente) {
            // Token di un modello diverso da Utente (non previsto): solo il super admin
            return $utente->isSuperAdmin();
        }

        if ($proprietario->is($utente)) {
            return true;
        }

        if ($utente->isSuperAdmin()) {
            return true;
        }

        return $this->viewAny($utente)
            && ! $proprietario->isSuperAdmin()
            && $proprietario->associazione_id === $utente->associazione_id;
    }

    /**
     * Utenti i cui token sono visibili a $utente nella pagina di gestione
     * (stesse regole di revoca()).
     *
     * @param  Builder<Utente>  $query
     * @return Builder<Utente>
     */
    public function queryUtentiGestibili(Builder $query, Utente $utente): Builder
    {
        if ($utente->isSuperAdmin()) {
            return $query;
        }

        if (! $this->viewAny($utente)) {
            return $query->whereKey($utente->getKey());
        }

        return $query->where('associazione_id', $utente->associazione_id)
            ->where('ruolo', '!=', Utente::RUOLO_SUPER_ADMIN);
    }
}
