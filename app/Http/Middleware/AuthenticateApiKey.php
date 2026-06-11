<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticazione REST API v1 tramite API key Bearer (tabella api_keys).
 *
 * - Chiave scoped: associazione_id valorizzato → accesso al solo tenant della chiave.
 * - Chiave globale: associazione_id NULL (solo super_admin) → accesso cross-tenant.
 *
 * Uso: ->middleware(AuthenticateApiKey::class.':eventi:read')
 * Il parametro è il permesso richiesto (con supporto wildcard, vedi ApiKey::hasPermesso).
 * Senza parametro la chiave deve essere solo valida (endpoint di verifica).
 *
 * La chiave autenticata è disponibile in $request->attributes->get('api_key').
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, ?string $permesso = null): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->errorResponse(401, 'missing_api_key', 'API key mancante. Usa l\'header Authorization: Bearer <chiave>.');
        }

        /** @var ApiKey|null $apiKey */
        $apiKey = ApiKey::query()->where('api_key', $token)->first();

        if (! $apiKey || ! $apiKey->isValida()) {
            return $this->errorResponse(401, 'invalid_api_key', 'API key non valida, disattivata o scaduta.');
        }

        if ($permesso !== null && ! $apiKey->hasPermesso($permesso)) {
            return $this->errorResponse(403, 'forbidden', "Permesso mancante: {$permesso}.");
        }

        // Traccia l'ultimo utilizzo senza scatenare eventi/audit log
        $apiKey->newQuery()->whereKey($apiKey->id)->update(['ultimo_utilizzo' => now()]);

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    private function errorResponse(int $status, string $code, string $message): Response
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], $status);
    }
}
