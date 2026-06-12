<?php

use App\Http\Controllers\Piattaforma\AuditAccessiController;
use App\Http\Controllers\Piattaforma\CestinoController;
use App\Http\Controllers\Piattaforma\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: piattaforma (sezione E del piano "Gestione 2", f.23–25)
|--------------------------------------------------------------------------
|
| - f.23 Onboarding admin: dismiss persistente della card checklist in dashboard;
| - f.24 Cestino unificato: soft-deleted di soci/eventi/documenti/verbali con
|   ripristino ed eliminazione definitiva (permessi per modulo);
| - f.25 Audit accessi: consultazione della tabella `accessi` con filtri.
|   La pagina audit NON usa il middleware 'tenant': il super_admin in vista
|   globale vede anche le righe senza tenant (tentativi su email sconosciute).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {

    // ----- f.23 Onboarding admin -----
    Route::post('/onboarding/dismiss', [OnboardingController::class, 'dismiss'])
        ->name('onboarding.dismiss');

    // ----- f.24 Cestino unificato -----
    Route::prefix('cestino')->name('cestino.')->group(function () {
        Route::get('/', [CestinoController::class, 'index'])->name('index');
        Route::post('{tipo}/{id}/ripristina', [CestinoController::class, 'ripristina'])
            ->name('ripristina');
        Route::delete('{tipo}/{id}', [CestinoController::class, 'eliminaDefinitivamente'])
            ->name('elimina-definitivamente');
    });
});

// ----- f.25 Audit accessi (senza 'tenant': vista globale super_admin ammessa) -----
Route::middleware(['auth'])->group(function () {
    Route::get('/audit-accessi', [AuditAccessiController::class, 'index'])
        ->name('audit-accessi.index');
});
