<?php

use App\Http\Controllers\AreaSoci\DiscussioniController;
use App\Http\Controllers\Comunicazione\DiscussioniModerazioneController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: discussioni tra soci (issue #13)
|--------------------------------------------------------------------------
|
| Area soci: spazi, discussioni, risposte, segnalazioni e "segui".
| Gestionale: moderazione (spazi, segnalazioni, messaggi, sospensioni).
| Governance: DiscussioneSpazioPolicy, famiglia di permessi comunicazione.*.
|
*/

Route::middleware(['auth', 'tenant'])->prefix('discussioni')->name('discussioni.')->group(function () {
    Route::get('/', [DiscussioniModerazioneController::class, 'index'])->name('index');
    Route::post('spazi', [DiscussioniModerazioneController::class, 'storeSpazio'])->name('spazi.store');
    Route::put('spazi/{spazio}', [DiscussioniModerazioneController::class, 'updateSpazio'])->name('spazi.update');
    Route::post('regolamento', [DiscussioniModerazioneController::class, 'regolamento'])->name('regolamento');
    Route::get('{discussione}', [DiscussioniModerazioneController::class, 'show'])->name('show');
    Route::patch('{discussione}', [DiscussioniModerazioneController::class, 'aggiornaDiscussione'])->name('update');
    Route::post('messaggi/{messaggio}/nascondi', [DiscussioniModerazioneController::class, 'nascondi'])->name('messaggi.nascondi');
    Route::post('messaggi/{messaggio}/ripristina', [DiscussioniModerazioneController::class, 'ripristina'])->name('messaggi.ripristina');
    Route::post('messaggi/{messaggio}/archivia-segnalazioni', [DiscussioniModerazioneController::class, 'archiviaSegnalazioni'])->name('messaggi.archivia');
    Route::post('soci/{socio}/sospendi', [DiscussioniModerazioneController::class, 'sospendi'])->name('soci.sospendi');
    Route::post('soci/{socio}/riattiva', [DiscussioniModerazioneController::class, 'riattiva'])->name('soci.riattiva');
});

Route::prefix('area-soci/discussioni')
    ->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function (): void {
        Route::get('/', [DiscussioniController::class, 'index'])->name('discussioni');
        Route::post('regolamento', [DiscussioniController::class, 'accettaRegolamento'])->name('discussioni.regolamento');
        Route::get('spazi/{spazio}', [DiscussioniController::class, 'spazio'])->name('discussioni.spazio');
        Route::post('spazi/{spazio}', [DiscussioniController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('discussioni.store');
        Route::get('{discussione}', [DiscussioniController::class, 'show'])->name('discussioni.show');
        Route::post('{discussione}/risposte', [DiscussioniController::class, 'rispondi'])
            ->middleware('throttle:10,1')
            ->name('discussioni.rispondi');
        Route::post('{discussione}/segui', [DiscussioniController::class, 'segui'])->name('discussioni.segui');
        Route::delete('{discussione}/segui', [DiscussioniController::class, 'nonSeguire'])->name('discussioni.non-seguire');
        Route::post('messaggi/{messaggio}/segnala', [DiscussioniController::class, 'segnala'])
            ->middleware('throttle:10,1')
            ->name('discussioni.segnala');
    });
