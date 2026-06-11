<?php

namespace App\Http\Middleware\FrontendPubblico;

use App\Models\Account;
use App\Models\Associazione;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risolve il tenant del frontend pubblico dallo slug nell'URL (/a/{slug}):
 * - 404 uniforme (anti-enumerazione) se lo slug non esiste o il frontend
 *   pubblico dell'associazione è disattivato;
 * - logout forzato della guard 'account' se la sessione appartiene a un
 *   account di un ALTRO tenant (navigazione cross-tenant nello stesso browser);
 * - binding app('tenant.pubblico') = id associazione, letto da
 *   BelongsToAssociazione::currentAssociazioneId() prima del fallback sessione.
 */
class RisolviTenantPubblico
{
    public function handle(Request $request, Closure $next): Response
    {
        $associazione = $request->route('associazione');

        if (! $associazione instanceof Associazione) {
            $associazione = Associazione::query()->where('slug', $associazione)->first();
        }

        if ($associazione === null || ! $associazione->frontend_pubblico_attivo) {
            abort(404);
        }

        $this->logoutAccountCrossTenant($request, $associazione);

        app()->instance('tenant.pubblico', $associazione->id);

        return $next($request);
    }

    /**
     * Se nella sessione c'è un account di un altro tenant (o inesistente),
     * la guard 'account' viene sloggata: niente sessioni cross-associazione.
     */
    protected function logoutAccountCrossTenant(Request $request, Associazione $associazione): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $guard = Auth::guard('account');

        if (! $guard instanceof SessionGuard) {
            return;
        }

        $accountId = $request->session()->get($guard->getName());

        if ($accountId === null) {
            return;
        }

        // Lookup senza global scope: il tenant pubblico non è ancora bindato
        // e l'account potrebbe appartenere a un'altra associazione
        $account = Account::allTenants()->withTrashed()->find($accountId);

        if (! $account instanceof Account || $account->associazione_id !== $associazione->id) {
            $guard->logout();
            // regenerate (non invalidate): le altre guard nello stesso browser sopravvivono
            $request->session()->regenerate();
            $request->session()->regenerateToken();
        }
    }
}
