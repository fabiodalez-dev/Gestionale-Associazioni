<?php

use App\Http\Controllers\Api\V1\AssociazioneApiController;
use App\Http\Controllers\Api\V1\QuotaApiController;
use App\Http\Controllers\Api\V1\SocioApiController;
use App\Http\Controllers\Api\V1\TesseraApiController;
use App\Http\Controllers\ApiV1\ApiDocsPageController;
use App\Http\Controllers\ApiV1\ApiKeyController;
use App\Http\Controllers\ApiV1\ApiTokenController;
use App\Http\Middleware\ApiV1\AuthenticateApiV1;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Spatie\Csp\AddCspHeaders;

/*
|--------------------------------------------------------------------------
| Modulo: api-v1
|--------------------------------------------------------------------------
|
| 1. REST API /api/v1 (soci, tessere, quote, associazioni): autenticazione
|    doppia (chiavi legacy api_keys via Bearer 64-hex o X-API-Key, e token
|    Sanctum con abilities), rate limiting per credenziale, scoping
|    multi-tenant (chiavi scopate / globali super_admin), risposte JSON con
|    parità legacy. Gli endpoint eventi sono registrati dal modulo eventi.
| 2. /api/v1/tessere/verify: PUBBLICO (il QR sulla tessera contiene l'UUID),
|    throttle severo per IP.
| 3. Area admin: pagina "Chiavi API" (gestione api_keys + token Sanctum) e
|    pagina "Documentazione API"; la reference OpenAPI è su /docs/api (Scramble).
|
| UUID_PATTERN: i path /{id} accettano solo segmenti UUID-like; tutto il resto
| sotto i prefissi del modulo cade nel catch-all 405 method_not_allowed
| (parità legacy: "qualsiasi altra combinazione metodo/path → 405").
|
*/

$uuidPattern = '[a-fA-F0-9-]{36}';

// ---- REST API v1 (stateless: niente CSRF/CSP) ----
Route::prefix('api/v1')
    ->withoutMiddleware([ValidateCsrfToken::class, AddCspHeaders::class])
    ->group(function () use ($uuidPattern) {

        // Verifica tessera da QR code: PUBBLICA, throttle per IP
        Route::get('/tessere/verify', [TesseraApiController::class, 'verify'])
            ->middleware('throttle:api-v1-public')
            ->name('api.v1.tessere.verify');

        // ---- Endpoint autenticati (chiave api_keys o token Sanctum) ----

        // Tessere (sola lettura)
        Route::middleware([AuthenticateApiV1::class.':tessere:read', 'throttle:api-v1'])->group(function () use ($uuidPattern) {
            Route::get('/tessere/search', [TesseraApiController::class, 'search'])->name('api.v1.tessere.search');
            Route::get('/tessere/{id}', [TesseraApiController::class, 'show'])
                ->where('id', $uuidPattern)
                ->name('api.v1.tessere.show');
            Route::get('/tessere', [TesseraApiController::class, 'index'])->name('api.v1.tessere.index');
        });

        // Soci
        Route::middleware([AuthenticateApiV1::class.':soci:read', 'throttle:api-v1'])->group(function () use ($uuidPattern) {
            Route::get('/soci/search', [SocioApiController::class, 'search'])->name('api.v1.soci.search');
            Route::get('/soci/{id}', [SocioApiController::class, 'show'])
                ->where('id', $uuidPattern)
                ->name('api.v1.soci.show');
            Route::get('/soci', [SocioApiController::class, 'index'])->name('api.v1.soci.index');
        });

        Route::put('/soci/{id}', [SocioApiController::class, 'update'])
            ->where('id', $uuidPattern)
            ->middleware([AuthenticateApiV1::class.':soci:write', 'throttle:api-v1'])
            ->name('api.v1.soci.update');

        // Quote (sola lettura)
        Route::middleware([AuthenticateApiV1::class.':quote:read', 'throttle:api-v1'])->group(function () use ($uuidPattern) {
            Route::get('/quote/{socio_id}', [QuotaApiController::class, 'perSocio'])
                ->where('socio_id', $uuidPattern)
                ->name('api.v1.quote.perSocio');
            Route::get('/quote', [QuotaApiController::class, 'index'])->name('api.v1.quote.index');
        });

        // Associazioni: solo client globali (il controllo è nel controller → 403 global_only)
        Route::get('/associazioni', [AssociazioneApiController::class, 'index'])
            ->middleware([AuthenticateApiV1::class, 'throttle:api-v1'])
            ->name('api.v1.associazioni.index');

        // Catch-all 405 per i soli prefissi di QUESTO modulo (gli eventi e la
        // root sono del modulo eventi e non vanno intercettati).
        Route::any('/{path}', function () {
            return response()->json([
                'success' => false,
                'error' => 'Metodo o endpoint non supportato.',
                'code' => 'method_not_allowed',
            ], 405);
        })
            ->where('path', '(tessere|soci|quote|associazioni)(/.*)?')
            ->name('api.v1.method-not-allowed');
    });

// ---- Area amministrativa (sessione web) ----
// Nota: niente middleware 'tenant' — il super_admin senza associazione
// selezionata vede TUTTE le chiavi (vista globale, parità legacy §2.2).
Route::middleware(['auth'])->group(function () {
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::patch('/api-keys/{apiKey}/toggle', [ApiKeyController::class, 'toggle'])->name('api-keys.toggle');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    // Token personali Sanctum (miglioramento 23)
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Guida statica (la reference OpenAPI interattiva è su /docs/api via Scramble)
    Route::get('/api-docs', ApiDocsPageController::class)
        ->middleware('can:viewAny,App\Models\ApiKey')
        ->name('api-docs.index');
});
