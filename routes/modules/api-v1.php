<?php

use App\Http\Controllers\ApiV1\ApiDocsPageController;
use App\Http\Controllers\ApiV1\ApiKeyController;
use App\Http\Controllers\ApiV1\ApiTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: api-v1 (pagine web)
|--------------------------------------------------------------------------
|
| 1. REST API /api/v1 (soci, tessere, quote, associazioni): NON è qui. Le
|    rotte stanno in routes/modules/api/api-v1.php e sono caricate da
|    routes/api.php nel gruppo `api` (niente sessione né cookie), con gli
|    stessi URL, nomi e middleware (throttle prima di AuthenticateApiV1).
| 2. Area admin (sessione web): pagina "Chiavi API" (gestione api_keys +
|    token Sanctum personali), pagina "Token API" per la revoca dei token
|    altrui da parte degli amministratori e pagina "Documentazione API"; la
|    reference OpenAPI è su /docs/api (Scramble).
|
*/

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
    // Elenco dei token di tutti gli utenti gestibili (super_admin: tutti;
    // admin_associazione: utenti della propria associazione) per la revoca
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::delete('/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Guida statica (la reference OpenAPI interattiva è su /docs/api via Scramble)
    Route::get('/api-docs', ApiDocsPageController::class)
        ->middleware('can:viewAny,App\Models\ApiKey')
        ->name('api-docs.index');
});
