<?php

use App\Http\Controllers\Membri\CheckinKioskController;
use App\Http\Controllers\Membri\ModificaDatiAccountController;
use App\Http\Controllers\Membri\ModificaDatiSocioController;
use App\Http\Controllers\Membri\NucleoController;
use App\Http\Controllers\Membri\RichiestaModificaDatiAdminController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use App\Http\Middleware\FrontendPubblico\AutenticaAccount;
use App\Http\Middleware\FrontendPubblico\EnsureAccountValido;
use App\Http\Middleware\FrontendPubblico\EnsureAccountVerificato;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use App\Http\Middleware\ScannerSecurityHeaders;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;
use Spatie\Csp\AddCspHeaders;

/*
|--------------------------------------------------------------------------
| Modulo: membri (sezione D del piano "Gestione 2", f.19–f.22)
|--------------------------------------------------------------------------
|
| - f.19 Richieste modifica dati: il socio (area soci) o l'account
|   collegato propone modifiche anagrafiche; l'admin vede il diff e
|   approva/rifiuta (badge in sidebar su richieste-modifica-dati.index).
| - f.20 Nuclei familiari: gestione dalla scheda socio + vista raggruppata.
| - f.21 Check-in kiosk fullscreen per evento (layout dedicato senza
|   sidebar, ScannerSecurityHeaders per la fotocamera).
| - f.22 Soci minorenni: validazioni nei Form Request soci/iscrizione
|   (nessuna rotta dedicata).
|
*/

// ---- Area amministrativa (auth + tenant) ----
Route::middleware(['auth', 'tenant'])->group(function () {
    // f.19 — gestione richieste di modifica dati
    Route::get('/richieste-modifica-dati', [RichiestaModificaDatiAdminController::class, 'index'])
        ->name('richieste-modifica-dati.index');
    Route::post('/richieste-modifica-dati/{richiesta}/approva', [RichiestaModificaDatiAdminController::class, 'approva'])
        ->whereUuid('richiesta')->name('richieste-modifica-dati.approva');
    Route::post('/richieste-modifica-dati/{richiesta}/rifiuta', [RichiestaModificaDatiAdminController::class, 'rifiuta'])
        ->whereUuid('richiesta')->name('richieste-modifica-dati.rifiuta');

    // f.20 — nuclei familiari
    Route::get('/soci-nuclei', [NucleoController::class, 'index'])->name('nuclei.index');
    Route::post('/nuclei', [NucleoController::class, 'store'])->name('nuclei.store');
    Route::put('/nuclei/{nucleo}', [NucleoController::class, 'update'])
        ->whereUuid('nucleo')->name('nuclei.update');
    Route::delete('/nuclei/{nucleo}', [NucleoController::class, 'destroy'])
        ->whereUuid('nucleo')->name('nuclei.destroy');
    Route::post('/nuclei/{nucleo}/membri', [NucleoController::class, 'aggiungiMembro'])
        ->whereUuid('nucleo')->name('nuclei.membri.store');
    Route::delete('/nuclei/{nucleo}/membri/{socio}', [NucleoController::class, 'rimuoviMembro'])
        ->whereUuid('nucleo')->whereUuid('socio')->name('nuclei.membri.destroy');

    // f.21 — check-in kiosk per evento (fotocamera: header scanner dedicati)
    Route::get('/checkin-kiosk/{evento}', [CheckinKioskController::class, 'show'])
        ->whereUuid('evento')
        ->withoutMiddleware([SecurityHeaders::class, AddCspHeaders::class])
        ->middleware(ScannerSecurityHeaders::class)
        ->name('checkin-kiosk.show');
    Route::get('/checkin-kiosk/{evento}/contatore', [CheckinKioskController::class, 'contatore'])
        ->whereUuid('evento')
        ->middleware('throttle:120,1')
        ->name('checkin-kiosk.contatore');
});

// ---- Area soci (guard 'socio') — f.19 ----
Route::prefix('area-soci')->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function () {
        Route::get('/modifica-dati', [ModificaDatiSocioController::class, 'show'])->name('modifica-dati');
        Route::post('/modifica-dati', [ModificaDatiSocioController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('modifica-dati.store');
    });

// ---- Area account frontend pubblico (guard 'account') — f.19 ----
Route::prefix('a/{associazione:slug}/account')->name('pubblico.account.')
    ->middleware([
        RisolviTenantPubblico::class,
        AutenticaAccount::class.':account',
        EnsureAccountValido::class,
        EnsureAccountVerificato::class,
    ])
    ->group(function () {
        Route::get('/modifica-dati', [ModificaDatiAccountController::class, 'show'])->name('modifica-dati');
        Route::post('/modifica-dati', [ModificaDatiAccountController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('modifica-dati.store');
    });
