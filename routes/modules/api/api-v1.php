<?php

use App\Http\Controllers\Api\V1\AssociazioneApiController;
use App\Http\Controllers\Api\V1\QuotaApiController;
use App\Http\Controllers\Api\V1\SocioApiController;
use App\Http\Controllers\Api\V1\TesseraApiController;
use App\Http\Middleware\ApiV1\AuthenticateApiV1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo api-v1: REST API /api/v1 (soci, tessere, quote, associazioni)
|--------------------------------------------------------------------------
|
| Caricato da routes/api.php DENTRO il gruppo `api` con prefisso /api/v1:
| niente sessione, cookie, CSRF né CSP (la guardia installer è
| EnsureApiInstallata, che risponde 503 JSON invece di reindirizzare).
| Le pagine amministrative (/api-keys, /api-tokens, /api-docs) restano nel
| gruppo web, in routes/modules/api-v1.php.
|
| UUID_PATTERN: i path /{id} accettano solo segmenti UUID-like; tutto il resto
| sotto i prefissi del modulo cade nel catch-all 405 method_not_allowed
| (parità legacy: "qualsiasi altra combinazione metodo/path → 405").
|
*/

$uuidPattern = '[a-fA-F0-9-]{36}';

// Verifica tessera da QR code: PUBBLICA, throttle per IP
Route::get('/tessere/verify', [TesseraApiController::class, 'verify'])
    ->middleware('throttle:api-v1-public')
    ->name('api.v1.tessere.verify');

// ---- Endpoint autenticati (chiave api_keys o token Sanctum) ----
// Ordine dei middleware: throttle PRIMA di AuthenticateApiV1, così
// anche le richieste respinte (401/403) consumano la quota per IP e
// per credenziale presentata.

// Tessere (sola lettura)
Route::middleware(['throttle:api-v1', AuthenticateApiV1::class.':tessere:read'])->group(function () use ($uuidPattern) {
    Route::get('/tessere/search', [TesseraApiController::class, 'search'])->name('api.v1.tessere.search');
    Route::get('/tessere/{id}', [TesseraApiController::class, 'show'])
        ->where('id', $uuidPattern)
        ->name('api.v1.tessere.show');
    Route::get('/tessere', [TesseraApiController::class, 'index'])->name('api.v1.tessere.index');
});

// Soci
Route::middleware(['throttle:api-v1', AuthenticateApiV1::class.':soci:read'])->group(function () use ($uuidPattern) {
    Route::get('/soci/search', [SocioApiController::class, 'search'])->name('api.v1.soci.search');
    Route::get('/soci/{id}', [SocioApiController::class, 'show'])
        ->where('id', $uuidPattern)
        ->name('api.v1.soci.show');
    Route::get('/soci', [SocioApiController::class, 'index'])->name('api.v1.soci.index');
});

Route::put('/soci/{id}', [SocioApiController::class, 'update'])
    ->where('id', $uuidPattern)
    ->middleware(['throttle:api-v1', AuthenticateApiV1::class.':soci:write'])
    ->name('api.v1.soci.update');

// Quote (sola lettura)
Route::middleware(['throttle:api-v1', AuthenticateApiV1::class.':quote:read'])->group(function () use ($uuidPattern) {
    Route::get('/quote/{socio_id}', [QuotaApiController::class, 'perSocio'])
        ->where('socio_id', $uuidPattern)
        ->name('api.v1.quote.perSocio');
    Route::get('/quote', [QuotaApiController::class, 'index'])->name('api.v1.quote.index');
});

// Associazioni: solo client globali (il controllo è nel controller → 403 global_only)
Route::get('/associazioni', [AssociazioneApiController::class, 'index'])
    ->middleware(['throttle:api-v1', AuthenticateApiV1::class])
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
