<?php

use App\Http\Controllers\Struttura\AdempimentoController;
use App\Http\Controllers\Struttura\DecisioneController;
use App\Http\Controllers\Struttura\ElezioneController;
use App\Http\Controllers\Struttura\RiunioneController;
use App\Http\Controllers\Struttura\VotazioneOnlineController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: riunioni-decisioni (Organizzazione 3 — sezione D, f.17-20)
|--------------------------------------------------------------------------
|
| Riunioni del direttivo con OdG strutturato, inviti agli intestatari delle
| cariche (round 1) + convocazione email brandizzata, votazioni per punto e
| generazione bozza Verbale (modulo verbali esistente). Assemblee dei soci
| (issue #6): elenco aventi diritto, appello e quorum; deleghe (issue #7). Pagina Decisioni
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

        // Assemblea dei soci (issue #6): aventi diritto e appello
        Route::post('{riunione}/aventi-diritto', [RiunioneController::class, 'aventiDiritto'])->name('aventi-diritto');
        Route::get('{riunione}/appello', [RiunioneController::class, 'appello'])->name('appello');
        Route::post('{riunione}/appello', [RiunioneController::class, 'registraPresenzaCodice'])
            ->middleware('throttle:120,1')
            ->name('appello.codice');

        // Deleghe registrate dalla segreteria (issue #7)
        Route::post('{riunione}/deleghe', [RiunioneController::class, 'registraDelega'])->name('deleghe.store');
        Route::delete('{riunione}/deleghe/{delega}', [RiunioneController::class, 'revocaDelega'])->name('deleghe.destroy');

        // Elezioni in assemblea (issue #9)
        Route::post('{riunione}/elezioni', [ElezioneController::class, 'store'])->name('elezioni.store');

        // Voto online in assemblea (issue #8)
        Route::post('{riunione}/voto-online', [VotazioneOnlineController::class, 'apri'])->name('voto-online.apri');
        Route::post('{riunione}/voto-online/{votazione}/chiudi', [VotazioneOnlineController::class, 'chiudi'])->name('voto-online.chiudi');

        // Votazioni per punto OdG (f.18)
        Route::post('{riunione}/votazioni', [RiunioneController::class, 'registraVotazione'])->name('votazioni.store');
        // Genera bozza verbale precompilato (f.18)
        Route::post('{riunione}/genera-verbale', [RiunioneController::class, 'generaVerbale'])->name('genera-verbale');
    });

    // ----- Elezioni e rinnovo delle cariche (issue #9) -----
    Route::prefix('elezioni')->name('elezioni.')->group(function () {
        Route::get('{elezione}', [ElezioneController::class, 'show'])->name('show');
        Route::delete('{elezione}', [ElezioneController::class, 'destroy'])->name('destroy');
        Route::post('{elezione}/candidature', [ElezioneController::class, 'candida'])->name('candidature.store');
        Route::post('{elezione}/candidature/{candidatura}/accetta', [ElezioneController::class, 'accetta'])->name('candidature.accetta');
        Route::post('{elezione}/candidature/{candidatura}/ritira', [ElezioneController::class, 'ritira'])->name('candidature.ritira');
        Route::post('{elezione}/apri-voto', [ElezioneController::class, 'apriVoto'])->name('apri-voto');
        Route::post('{elezione}/scrutinio', [ElezioneController::class, 'scrutinio'])->name('scrutinio');
        Route::post('{elezione}/proclama', [ElezioneController::class, 'proclama'])->name('proclama');
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
