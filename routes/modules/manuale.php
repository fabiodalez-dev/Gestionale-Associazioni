<?php

use App\Http\Controllers\AreaSoci\ManualeController as ManualeSocioController;
use App\Http\Controllers\Struttura\ManualeController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: manuale operativo interno (issue #12)
|--------------------------------------------------------------------------
|
| Gestionale: pagine per sezione, modelli iniziali, checklist avviate dalle
| pagine. Area soci: sola lettura delle pagine con visibilità "soci".
| Governance: ManualePaginaPolicy (manuale.*).
|
*/

Route::middleware(['auth', 'tenant'])->prefix('manuale')->name('manuale.')->group(function () {
    Route::get('/', [ManualeController::class, 'index'])->name('index');
    Route::get('nuova', [ManualeController::class, 'create'])->name('create');
    Route::post('/', [ManualeController::class, 'store'])->name('store');
    Route::post('modelli', [ManualeController::class, 'modelli'])->name('modelli');
    Route::get('checklist/{esecuzione}', [ManualeController::class, 'esecuzione'])->name('esecuzioni.show');
    Route::post('checklist/{esecuzione}/passi/{passo}', [ManualeController::class, 'passo'])->name('esecuzioni.passo');
    Route::get('{pagina}', [ManualeController::class, 'show'])->name('show');
    Route::get('{pagina}/modifica', [ManualeController::class, 'edit'])->name('edit');
    Route::put('{pagina}', [ManualeController::class, 'update'])->name('update');
    Route::delete('{pagina}', [ManualeController::class, 'destroy'])->name('destroy');
    Route::post('{pagina}/checklist', [ManualeController::class, 'avvia'])->name('avvia');
});

Route::prefix('area-soci')
    ->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function (): void {
        Route::get('/guide', [ManualeSocioController::class, 'index'])->name('manuale');
        Route::get('/guide/{pagina}', [ManualeSocioController::class, 'show'])->name('manuale.show');
    });
