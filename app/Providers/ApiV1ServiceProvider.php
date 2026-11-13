<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\Utente;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo api-v1: rate limiting per credenziale e per IP (gap di sicurezza del
 * legacy) e gate di accesso alla documentazione Scramble (miglioramento 22).
 */
class ApiV1ServiceProvider extends ServiceProvider
{
    /** Richieste al minuto per singola credenziale presentata (valida o no). */
    public const LIMITE_CREDENZIALE = 120;

    /** Richieste al minuto per IP sugli endpoint autenticati, prima dell'autenticazione. */
    public const LIMITE_IP = 300;

    /** Richieste al minuto per IP sull'endpoint pubblico di verifica tessera. */
    public const LIMITE_PUBBLICO = 30;

    public function boot(): void
    {
        // Endpoint autenticati. Il limiter gira PRIMA del middleware di
        // autenticazione, così anche i tentativi falliti (chiave inesistente,
        // scaduta, senza permesso) consumano quota: limite per IP e, se è
        // stata presentata una credenziale, limite per credenziale (hash del
        // valore presentato, mai il valore in chiaro).
        RateLimiter::for('api-v1', function (Request $request) {
            $limiti = [
                Limit::perMinute(self::LIMITE_IP)->by('api-v1|ip|'.$request->ip())->response(fn () => self::rispostaRateLimited()),
            ];

            $credenziale = $request->bearerToken() ?: $request->header('X-API-Key');

            if (is_string($credenziale) && $credenziale !== '') {
                $limiti[] = Limit::perMinute(self::LIMITE_CREDENZIALE)
                    ->by('api-v1|cred|'.hash('sha256', $credenziale))
                    ->response(fn () => self::rispostaRateLimited());
            }

            return $limiti;
        });

        // Endpoint pubblico /tessere/verify: più severo, per IP.
        RateLimiter::for('api-v1-public', function (Request $request) {
            return Limit::perMinute(self::LIMITE_PUBBLICO)
                ->by('api-v1-public|'.$request->ip())
                ->response(fn () => self::rispostaRateLimited());
        });

        // Documentazione Scramble (/docs/api): stessa regola della pagina
        // "Chiavi API" (ApiKeyPolicy::viewAny): super_admin e admin_associazione,
        // non l'operatore né gli ospiti (RestrictedDocsAccess usa questo gate).
        Gate::define('viewApiDocs', fn (?Utente $utente) => $utente !== null && $utente->can('viewAny', ApiKey::class));
    }

    private static function rispostaRateLimited(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'Troppe richieste. Riprova tra qualche istante.',
            'code' => 'rate_limited',
        ], 429);
    }
}
