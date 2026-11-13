<?php

namespace App\Http\Middleware\FrontendPubblico;

use App\Models\Account;
use App\Models\Associazione;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risolve il tenant del frontend pubblico dallo slug nell'URL (/a/{slug}):
 * - 404 uniforme (anti-enumerazione) se lo slug non esiste o il frontend
 *   pubblico dell'associazione è disattivato;
 * - logout forzato della guard 'account' se la sessione appartiene a un
 *   account di un ALTRO tenant (navigazione cross-tenant nello stesso browser);
 *   se la rotta richiede l'account, la richiesta non prosegue con un account
 *   nullo ma viene rediretta alla login pubblica del tenant dello slug;
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

        $sloggato = $this->logoutAccountCrossTenant($request, $associazione);

        app()->instance('tenant.pubblico', $associazione->id);

        // Rotta riservata all'account: dopo il logout cross-tenant non si
        // prosegue con un account nullo, si riparte dalla login di QUESTO tenant
        if ($sloggato && $this->rottaRichiedeAccount($request)) {
            return redirect()->route('pubblico.login', ['associazione' => $associazione->slug]);
        }

        return $next($request);
    }

    /**
     * Se nella sessione c'è un account di un altro tenant (o inesistente),
     * la guard 'account' viene sloggata: niente sessioni cross-associazione.
     * Ritorna true se è stato eseguito il logout.
     */
    protected function logoutAccountCrossTenant(Request $request, Associazione $associazione): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $guard = Auth::guard('account');

        if (! $guard instanceof SessionGuard) {
            return false;
        }

        $accountId = $request->session()->get($guard->getName());

        if ($accountId === null) {
            return false;
        }

        // Lookup senza global scope: il tenant pubblico non è ancora bindato
        // e l'account potrebbe appartenere a un'altra associazione
        $account = Account::allTenants()->withTrashed()->find($accountId);

        if ($account instanceof Account && $account->associazione_id === $associazione->id) {
            return false;
        }

        $guard->logout();
        // regenerate (non invalidate): le altre guard nello stesso browser sopravvivono
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return true;
    }

    /**
     * Vero se la rotta corrente è protetta dalla guard 'account'
     * (middleware AutenticaAccount): le pagine pubbliche del mini-sito
     * restano navigabili come ospite anche dopo il logout cross-tenant.
     */
    protected function rottaRichiedeAccount(Request $request): bool
    {
        $rotta = $request->route();

        if (! $rotta instanceof Route) {
            return false;
        }

        foreach ($rotta->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, AutenticaAccount::class)) {
                return true;
            }
        }

        return false;
    }
}
