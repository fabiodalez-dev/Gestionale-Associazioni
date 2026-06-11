<?php

namespace App\Http\Middleware;

use App\Models\Utente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware 'tenant': garantisce che la richiesta abbia un contesto associazione.
 *
 * - admin_associazione / operatore: associazione_id dell'utente, riflessa in sessione;
 * - super_admin: deve aver selezionato un'associazione (session('associazione_id')),
 *   altrimenti redirect alla dashboard (vista globale) con messaggio.
 */
class EnsureTenantSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Utente) {
            if (! $user->isSuperAdmin() && $user->associazione_id) {
                // Tenant fisso dell'utente: tenuto allineato in sessione
                if (session('associazione_id') !== $user->associazione_id) {
                    session(['associazione_id' => $user->associazione_id]);
                }

                return $next($request);
            }

            if ($user->isSuperAdmin() && session('associazione_id')) {
                return $next($request);
            }
        } elseif ($user !== null && ! empty($user->associazione_id)) {
            // Guard 'socio': il tenant è quello del socio autenticato
            if (session('associazione_id') !== $user->associazione_id) {
                session(['associazione_id' => $user->associazione_id]);
            }

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => 'Nessuna associazione selezionata.',
                'code' => 'TENANT_NOT_SELECTED',
            ], 403);
        }

        return redirect()
            ->route('dashboard')
            ->with('warning', 'Seleziona prima un\'associazione.');
    }
}
