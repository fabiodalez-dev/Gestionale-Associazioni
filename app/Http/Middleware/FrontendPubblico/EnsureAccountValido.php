<?php

namespace App\Http\Middleware\FrontendPubblico;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pattern EnsureSocioValido: se l'account autenticato è stato disattivato
 * dall'admin o cancellato (soft delete / anonimizzazione GDPR), la guard
 * viene sloggata e l'utente è rimandato alla login pubblica del tenant.
 */
class EnsureAccountValido
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user('account');

        if ($account instanceof Account && ($account->trashed() || ! $account->attivo)) {
            Auth::guard('account')->logout();
            // regenerate (non invalidate): le altre guard nello stesso browser sopravvivono
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            $slug = AutenticaAccount::slugTenantCorrente($request);

            if ($slug !== null && Route::has('pubblico.login')) {
                return redirect()->route('pubblico.login', ['associazione' => $slug]);
            }

            abort(403, 'Account non valido.');
        }

        return $next($request);
    }
}
