<?php

use App\Http\Controllers\DocumentiVerbaliScadenze\DocumentoController;
use App\Http\Controllers\DocumentiVerbaliScadenze\DocumentoSocioController;
use App\Http\Controllers\DocumentiVerbaliScadenze\ScadenzaController;
use App\Http\Controllers\DocumentiVerbaliScadenze\VerbaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: documenti-verbali-scadenze
|--------------------------------------------------------------------------
|
| Documenti associazione e fascicolo socio (medialibrary + download firmati
| temporanei, miglioramento 16), verbali con firmatari e PDF, scadenze con
| promemoria, soft delete e cestino (miglioramento 11).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {

    // ----- Documenti associazione (spec §1) -----
    Route::prefix('documenti')->name('documenti.')->group(function () {
        Route::get('/', [DocumentoController::class, 'index'])->name('index');
        Route::post('/', [DocumentoController::class, 'store'])->name('store');
        Route::get('cestino', [DocumentoController::class, 'cestino'])->name('cestino');
        Route::post('{id}/ripristina', [DocumentoController::class, 'ripristina'])->name('ripristina');
        Route::delete('{id}/definitivo', [DocumentoController::class, 'eliminaDefinitivamente'])->name('elimina-definitivamente');
        Route::get('{documento}/download', [DocumentoController::class, 'download'])->name('download');
        Route::post('{documento}/link-temporaneo', [DocumentoController::class, 'linkTemporaneo'])->name('link-temporaneo');
        Route::delete('{documento}', [DocumentoController::class, 'destroy'])->name('destroy');
    });

    // ----- Documenti fascicolo socio (estensione §6.1) -----
    Route::prefix('documenti-socio')->name('documenti-socio.')->group(function () {
        Route::get('/', [DocumentoSocioController::class, 'index'])->name('index');
        Route::post('/', [DocumentoSocioController::class, 'store'])->name('store');
        Route::post('{id}/ripristina', [DocumentoSocioController::class, 'ripristina'])->name('ripristina');
        Route::get('{documento}/download', [DocumentoSocioController::class, 'download'])->name('download');
        Route::post('{documento}/link-temporaneo', [DocumentoSocioController::class, 'linkTemporaneo'])->name('link-temporaneo');
        Route::delete('{documento}', [DocumentoSocioController::class, 'destroy'])->name('destroy');
    });

    // ----- Verbali (spec §2) -----
    Route::prefix('verbali')->name('verbali.')->group(function () {
        Route::get('/', [VerbaleController::class, 'index'])->name('index');
        Route::post('/', [VerbaleController::class, 'store'])->name('store');
        Route::get('cestino', [VerbaleController::class, 'cestino'])->name('cestino');
        Route::post('{id}/ripristina', [VerbaleController::class, 'ripristina'])->name('ripristina');
        Route::delete('{id}/definitivo', [VerbaleController::class, 'eliminaDefinitivamente'])->name('elimina-definitivamente');
        Route::post('{verbale}/approva', [VerbaleController::class, 'approva'])->name('approva');
        Route::get('{verbale}/pdf', [VerbaleController::class, 'pdf'])->name('pdf');
        Route::post('{verbale}/firmatari', [VerbaleController::class, 'storeFirmatario'])->name('firmatari.store');
        Route::post('{verbale}/firmatari/{firmatario}/firma', [VerbaleController::class, 'firmaFirmatario'])->name('firmatari.firma');
        Route::delete('{verbale}/firmatari/{firmatario}', [VerbaleController::class, 'destroyFirmatario'])->name('firmatari.destroy');
        Route::put('{verbale}', [VerbaleController::class, 'update'])->name('update');
        Route::delete('{verbale}', [VerbaleController::class, 'destroy'])->name('destroy');
    });

    // ----- Scadenze (spec §3) -----
    Route::prefix('scadenze')->name('scadenze.')->group(function () {
        Route::get('/', [ScadenzaController::class, 'index'])->name('index');
        Route::post('/', [ScadenzaController::class, 'store'])->name('store');
        Route::get('esporta-csv', [ScadenzaController::class, 'esportaCsv'])->name('esporta-csv');
        Route::post('{scadenza}/completa', [ScadenzaController::class, 'completa'])->name('completa');
        Route::put('{scadenza}', [ScadenzaController::class, 'update'])->name('update');
        Route::delete('{scadenza}', [ScadenzaController::class, 'destroy'])->name('destroy');
    });
});

/*
| Rotte pubbliche (senza auth): download via URL firmato temporaneo
| (miglioramento 16). La validità/scadenza è garantita dal middleware 'signed'.
*/
Route::get('condivisi/documenti/{id}', [DocumentoController::class, 'downloadPubblico'])
    ->middleware('signed')
    ->name('documenti.download-pubblico');

Route::get('condivisi/documenti-socio/{id}', [DocumentoSocioController::class, 'downloadPubblico'])
    ->middleware('signed')
    ->name('documenti-socio.download-pubblico');
