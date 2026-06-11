<?php

use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;
use Spatie\Health\Http\Middleware\RequiresSecretToken;

/*
|--------------------------------------------------------------------------
| Modulo: operations (salute applicativa) — miglioramento n. 20
|--------------------------------------------------------------------------
|
| /health        → pagina HTML con l'esito degli health check (DB, coda,
|                  scheduler, spazio disco, debug mode). Riservata al
|                  super_admin; ?fresh per rieseguire i check al volo.
| /health/json   → stesso contenuto in JSON, pensata per i sistemi di
|                  uptime monitoring esterni: protetta dall'header
|                  X-Secret-Token (env HEALTH_SECRET_TOKEN) invece che
|                  dalla sessione.
|
| Il liveness check "minimale" senza auth resta il nativo /up
| (bootstrap/app.php).
|
*/

Route::middleware(['auth', 'role:super_admin'])->group(function () {
    Route::get('/health', HealthCheckResultsController::class)->name('health');
});

// Registrata solo se il token è configurato: senza token il middleware
// del pacchetto lascerebbe passare chiunque.
if (config('health.secret_token')) {
    Route::middleware([RequiresSecretToken::class])->group(function () {
        Route::get('/health/json', HealthCheckJsonResultsController::class)->name('health.json');
    });
}
