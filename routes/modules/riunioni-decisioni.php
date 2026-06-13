<?php

use App\Http\Controllers\Struttura\AdempimentoController;
use App\Http\Controllers\Struttura\DecisioneController;
use App\Http\Controllers\Struttura\RiunioneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: riunioni-decisioni (Organizzazione 3 — sezione D, f.17-20)
|--------------------------------------------------------------------------
|
| Riunioni del direttivo con OdG strutturato, inviti agli intestatari delle
| cariche (round 1) + convocazione email brandizzata, votazioni per punto e
| generazione bozza Verbale (modulo verbali esistente). Pagina Decisioni
| aggregata e ricercabile. Adempimenti ricorrenti con checklist per anno
| dentro lo Scadenzario esistente.
|
| Governance: policy famiglia struttura.* (RiunionePolicy, AdempimentoPolicy).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {

    // ----- Riunioni del direttivo (f.17-18) -----
    Route::prefix('riunioni')->name('riunioni.')->group(function () {
        Route::get('/', [RiunioneController::class, 'index'])->name('index');
        Route::post('/', [RiunioneController::class, 'store'])->name('store');
        Route::get('{riunione}', [RiunioneController::class, 'show'])->name('show');
        Route::put('{riunione}', [RiunioneController::class, 'update'])->name('update');
        Route::delete('{riunione}', [RiunioneController::class, 'destroy'])->name('destroy');

        // Convocazione email ai membri dell'organo (f.17)
        Route::post('{riunione}/convoca', [RiunioneController::class, 'convoca'])->name('convoca');
        // Stato presenza di un partecipante (f.17)
        Route::put('{riunione}/partecipanti/{partecipante}/presenza', [RiunioneController::class, 'aggiornaPresenza'])
            ->name('partecipanti.presenza');

        // Votazioni per punto OdG (f.18)
        Route::post('{riunione}/votazioni', [RiunioneController::class, 'registraVotazione'])->name('votazioni.store');
        // Genera bozza verbale precompilato (f.18)
        Route::post('{riunione}/genera-verbale', [RiunioneController::class, 'generaVerbale'])->name('genera-verbale');
    });

    // ----- Archivio Decisioni (f.19) -----
    Route::get('/decisioni', [DecisioneController::class, 'index'])->name('decisioni.index');

    // ----- Adempimenti ricorrenti (f.20) -----
    Route::prefix('adempimenti')->name('adempimenti.')->group(function () {
        Route::get('/', [AdempimentoController::class, 'index'])->name('index');
        Route::post('/', [AdempimentoController::class, 'store'])->name('store');
        Route::post('seed', [AdempimentoController::class, 'seed'])->name('seed');
        Route::put('{adempimento}', [AdempimentoController::class, 'update'])->name('update');
        Route::delete('{adempimento}', [AdempimentoController::class, 'destroy'])->name('destroy');
        // Spunta/de-spunta la checklist annuale (dalla pagina Scadenzario, f.20)
        Route::post('{adempimento}/toggle-anno', [AdempimentoController::class, 'toggleAnno'])->name('toggle-anno');
    });
});
