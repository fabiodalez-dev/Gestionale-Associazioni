<?php

use App\Http\Controllers\Piattaforma\AuditAccessiController;
use App\Http\Controllers\Piattaforma\CestinoController;
use App\Http\Controllers\Piattaforma\OnboardingController;
use App\Http\Controllers\Piattaforma\RegistrazioneAssociazioneController;
use App\Http\Controllers\Piattaforma\RichiesteRegistrazioneController;
use Illuminate\Support\Facades\Route;
use Spatie\Honeypot\ProtectAgainstSpam;

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

/*
 * Iscrizione autonoma delle associazioni alla piattaforma (pubblica) e
 * gestione delle richieste da parte del super admin.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/registrati', [RegistrazioneAssociazioneController::class, 'create'])
        ->name('registrazione.create');
    Route::post('/registrati', [RegistrazioneAssociazioneController::class, 'store'])
        ->middleware([ProtectAgainstSpam::class, 'throttle:5,1'])
        ->name('registrazione.store');
    Route::get('/registrati/inviata', [RegistrazioneAssociazioneController::class, 'inviata'])
        ->name('registrazione.inviata');
});

// Link firmato dell'email di conferma: vale anche se chi clicca ha già una sessione aperta
Route::get('/registrati/conferma/{richiesta}', [RegistrazioneAssociazioneController::class, 'verifica'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('registrazione.verifica');

Route::middleware('auth')->prefix('richieste-registrazione')->name('richieste-registrazione.')->group(function (): void {
    Route::get('/', [RichiesteRegistrazioneController::class, 'index'])->name('index');
    Route::post('/{richiesta}/approva', [RichiesteRegistrazioneController::class, 'approva'])->name('approva');
    Route::post('/{richiesta}/rifiuta', [RichiesteRegistrazioneController::class, 'rifiuta'])->name('rifiuta');
});
