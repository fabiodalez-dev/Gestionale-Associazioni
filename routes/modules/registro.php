<?php

use App\Http\Controllers\Registro\CaricaController;
use App\Http\Controllers\Registro\CessazioneController;
use App\Http\Controllers\Registro\LibroSociController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: registro (docs/PIANO-VITA-SOCIALE.md, sezione C — f.13/15/16)
|--------------------------------------------------------------------------
|
| - Libro soci ufficiale (f.13): registro cronologico per anno con numero
|   progressivo di iscrizione, export PDF intestato ed Excel;
| - Cessazioni formali (f.15): registro dimissioni/decadenza/espulsione/
|   decesso con aggiornamento stato socio e annullamento tessere attive;
| - Organi sociali (f.16): cariche attive e storiche con alert mandati
|   in scadenza.
|
| Le delibere di ammissione (f.14) estendono il modulo iscrizioni; i
| verbali potenziati (f.17) estendono il modulo documenti-verbali-scadenze.
*/

Route::middleware(['auth', 'tenant'])->group(function () {

    // ----- Libro soci ufficiale (f.13) -----
    Route::prefix('libro-soci')->name('libro-soci.')->group(function () {
        Route::get('/', [LibroSociController::class, 'index'])->name('index');
        Route::get('pdf', [LibroSociController::class, 'pdf'])->name('pdf');
        Route::get('excel', [LibroSociController::class, 'excel'])->name('excel');
    });

    // ----- Cessazioni formali (f.15) -----
    Route::prefix('cessazioni')->name('cessazioni.')->group(function () {
        Route::get('/', [CessazioneController::class, 'index'])->name('index');
        Route::post('/', [CessazioneController::class, 'store'])->name('store');
    });

    // ----- Organi sociali (f.16) -----
    Route::prefix('organi-sociali')->name('organi-sociali.')->group(function () {
        Route::get('/', [CaricaController::class, 'index'])->name('index');
        Route::post('/', [CaricaController::class, 'store'])->name('store');
        Route::put('{carica}', [CaricaController::class, 'update'])->name('update');
        Route::delete('{carica}', [CaricaController::class, 'destroy'])->name('destroy');
    });
});
