<?php

use App\Http\Controllers\Tessere\CheckinController;
use App\Http\Controllers\Tessere\ScannerTesseraController;
use App\Http\Controllers\Tessere\TesseraController;
use App\Http\Controllers\Tessere\TesseraTemplateController;
use App\Http\Controllers\Tessere\VerificaTesseraController;
use App\Http\Middleware\ScannerSecurityHeaders;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;
use Spatie\Csp\AddCspHeaders;

/*
|--------------------------------------------------------------------------
| Modulo: tessere
|--------------------------------------------------------------------------
|
| Area admin (auth + tenant): gestione tessere, generazione massiva,
| emissione on-demand, PDF, stampa card, template per tipo socio, check-in.
|
| Area pubblica (no auth, rate-limited): verifica tessera via QR
| (HTML + JSON) e scanner QR standalone.
|
| Le pagine con scanner QR sostituiscono gli header di sicurezza globali
| (Permissions-Policy camera=() e CSP spatie) con ScannerSecurityHeaders.
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/tessere', [TesseraController::class, 'index'])->name('tessere.index');
    Route::get('/tessere/export', [TesseraController::class, 'export'])->name('tessere.export');
    Route::post('/tessere', [TesseraController::class, 'store'])->name('tessere.store');
    Route::post('/tessere/genera-massivo', [TesseraController::class, 'generaMassivo'])->name('tessere.genera-massivo');

    // Template testo tessera per tipo socio
    Route::get('/tessere/templates', [TesseraTemplateController::class, 'index'])->name('tessere.templates.index');
    Route::post('/tessere/templates', [TesseraTemplateController::class, 'store'])->name('tessere.templates.store');
    Route::put('/tessere/templates/{template}', [TesseraTemplateController::class, 'update'])
        ->whereUuid('template')->name('tessere.templates.update');
    Route::delete('/tessere/templates/{template}', [TesseraTemplateController::class, 'destroy'])
        ->whereUuid('template')->name('tessere.templates.destroy');

    // Check-in eventi via scansione tessera (pagina con fotocamera)
    Route::get('/tessere/checkin', [CheckinController::class, 'index'])
        ->withoutMiddleware([SecurityHeaders::class, AddCspHeaders::class])
        ->middleware(ScannerSecurityHeaders::class)
        ->name('tessere.checkin');
    Route::post('/tessere/checkin', [CheckinController::class, 'store'])->name('tessere.checkin.store');

    Route::put('/tessere/{tessera}', [TesseraController::class, 'update'])
        ->whereUuid('tessera')->name('tessere.update');
    Route::delete('/tessere/{tessera}', [TesseraController::class, 'destroy'])
        ->whereUuid('tessera')->name('tessere.destroy');

    // Download PDF (GET idempotente) e stampa card della tessera ESISTENTE
    Route::get('/tessere/{tessera}/pdf', [TesseraController::class, 'pdf'])
        ->whereUuid('tessera')->name('tessere.pdf');
    Route::get('/tessere/{tessera}/stampa', [TesseraController::class, 'stampa'])
        ->whereUuid('tessera')->name('tessere.stampa');

    // Emissione/rinnovo on-demand per socio (fix bug legacy §6.6: POST esplicito)
    Route::post('/soci/{socio}/tessere', [TesseraController::class, 'emetti'])
        ->whereUuid('socio')->name('tessere.emetti');
});

/*
| Rotte pubbliche (nessuna autenticazione): la tessera è verificabile da
| chiunque possieda l'UUID (capability token); i QR contengono URL firmati.
*/
Route::middleware(['throttle:30,1'])->group(function () {
    Route::get('/verifica-tessera/{tessera?}', [VerificaTesseraController::class, 'show'])
        ->name('tessere.verifica');
    Route::get('/api/verifica-tessera/{tessera}', [VerificaTesseraController::class, 'json'])
        ->name('tessere.verifica.json');
});

Route::get('/scanner-tessera', ScannerTesseraController::class)
    ->withoutMiddleware([SecurityHeaders::class, AddCspHeaders::class])
    ->middleware([ScannerSecurityHeaders::class, 'throttle:30,1'])
    ->name('tessere.scanner');
