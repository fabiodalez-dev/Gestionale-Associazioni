<?php

namespace App\Providers;

use App\Models\Utente;
use App\Services\ApiV1\ApiClientContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo api-v1: rate limiting per credenziale (gap di sicurezza del legacy)
 * e gate di accesso alla documentazione Scramble (miglioramento 22).
 */
class ApiV1ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Endpoint autenticati: 120 richieste/minuto per chiave/token
        // (fallback IP se il middleware di autenticazione non è ancora passato).
        RateLimiter::for('api-v1', function (Request $request) {
            $client = $request->attributes->get('api_client');

            $key = $client instanceof ApiClientContext
                ? $client->rateLimitKey()
                : 'api-v1|ip|'.$request->ip();

            return Limit::perMinute(120)->by($key)->response(fn () => response()->json([
                'success' => false,
                'error' => 'Troppe richieste. Riprova tra qualche istante.',
                'code' => 'rate_limited',
            ], 429));
        });

        // Endpoint pubblico /tessere/verify: più severo, per IP.
        RateLimiter::for('api-v1-public', function (Request $request) {
            return Limit::perMinute(30)->by('api-v1-public|'.$request->ip())->response(fn () => response()->json([
                'success' => false,
                'error' => 'Troppe richieste. Riprova tra qualche istante.',
                'code' => 'rate_limited',
            ], 429));
        });

        // Documentazione Scramble (/docs/api): accessibile agli utenti admin loggati
        // anche fuori dall'ambiente local (RestrictedDocsAccess usa questo gate).
        Gate::define('viewApiDocs', fn (?Utente $utente) => $utente !== null);
    }
}
