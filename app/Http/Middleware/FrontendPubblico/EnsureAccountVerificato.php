<?php

namespace App\Http\Middleware\FrontendPubblico;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Variante tenant-aware di 'verified': blocca le rotte autenticate del
 * frontend pubblico finché l'account non ha verificato l'email.
 * Reindirizza all'avviso di verifica del tenant (pubblico.verifica-email.avviso)
 * se la rotta esiste, altrimenti 403.
 */
class EnsureAccountVerificato
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user('account');

        if (! $account instanceof Account || $account->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Email non verificata.');
        }

        $slug = AutenticaAccount::slugTenantCorrente($request);

        if ($slug !== null && Route::has('pubblico.verifica-email.avviso')) {
            return redirect()->route('pubblico.verifica-email.avviso', ['associazione' => $slug]);
        }

        abort(403, 'Email non verificata.');
    }
}
