<?php

use App\Http\Controllers\AreaSoci\RubricaController;
use App\Http\Controllers\Comunicazione\RubricaImpostazioniController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: rubrica interna dei soci con consenso (issue #14)
|--------------------------------------------------------------------------
|
| Area soci: scheda con consenso, consultazione, contatto mediato.
| Gestionale: impostazioni (solo admin) e segnalazioni.
|
*/

Route::middleware(['auth', 'tenant'])->prefix('rubrica-soci')->name('rubrica-soci.')->group(function () {
    Route::get('/', [RubricaImpostazioniController::class, 'index'])->name('index');
    Route::put('/', [RubricaImpostazioniController::class, 'update'])->name('update');
});

Route::prefix('area-soci/rubrica')
    ->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function (): void {
        Route::get('/', [RubricaController::class, 'index'])->middleware('throttle:60,1')->name('rubrica');
        Route::put('scheda', [RubricaController::class, 'salva'])->middleware('throttle:10,1')->name('rubrica.salva');
        Route::delete('scheda', [RubricaController::class, 'revoca'])->name('rubrica.revoca');
        Route::post('{scheda}/contatta', [RubricaController::class, 'contatta'])->middleware('throttle:10,1')->name('rubrica.contatta');
        Route::get('segnala/{contatto}', [RubricaController::class, 'confermaSegnalazione'])->name('rubrica.segnala');
        Route::post('segnala/{contatto}', [RubricaController::class, 'segnala'])->name('rubrica.segnala.store');
    });
