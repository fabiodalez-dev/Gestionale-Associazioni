<?php

use App\Http\Controllers\SpaziRisorse\AttrezzaturaController;
use App\Http\Controllers\SpaziRisorse\PrenotazioneSpazioController;
use App\Http\Controllers\SpaziRisorse\PrestitoAttrezzaturaController;
use App\Http\Controllers\SpaziRisorse\SpazioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: spazi-risorse (sezione B del piano "Organizzazione 3", f.7–f.11)
|--------------------------------------------------------------------------
|
| f.7  Spazi: anagrafica sale/locali (CRUD), collegabili a sede.
| f.8  Prenotazioni spazi interne: rilevazione conflitti di overlap +
|      vista calendario settimanale per spazio + lista.
| f.9  Inventario attrezzature: CRUD con ricerca e filtro stato.
| f.10 Prestiti attrezzature ai soci: disponibilità calcolata, blocco se
|      non disponibile, registra restituzione, storico nella scheda socio.
| f.11 Il select "spazio" sul form evento e il warning conflitti non
|      bloccante vivono nel modulo eventi (EventoController/EventoRequest).
|
| Permessi: famiglia eventi.* (Regole operative del piano). Le rotte
| compaiono in sidebar tramite i nomi attesi (spazi.*, attrezzature.*,
| prestiti.*) già predisposti da Fondamenta ($nav tollerante).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    // ---- f.7 Spazi (CRUD) ----
    Route::get('/spazi', [SpazioController::class, 'index'])->name('spazi.index');
    Route::post('/spazi', [SpazioController::class, 'store'])->name('spazi.store');
    Route::put('/spazi/{spazio}', [SpazioController::class, 'update'])
        ->whereUuid('spazio')->name('spazi.update');
    Route::delete('/spazi/{spazio}', [SpazioController::class, 'destroy'])
        ->whereUuid('spazio')->name('spazi.destroy');

    // ---- f.8 Prenotazioni spazi (calendario settimanale + lista + conflitti) ----
    Route::get('/spazi/prenotazioni', [PrenotazioneSpazioController::class, 'index'])
        ->name('spazi.prenotazioni.index');
    Route::post('/spazi/prenotazioni', [PrenotazioneSpazioController::class, 'store'])
        ->name('spazi.prenotazioni.store');
    Route::put('/spazi/prenotazioni/{prenotazione}', [PrenotazioneSpazioController::class, 'update'])
        ->whereUuid('prenotazione')->name('spazi.prenotazioni.update');
    Route::delete('/spazi/prenotazioni/{prenotazione}', [PrenotazioneSpazioController::class, 'destroy'])
        ->whereUuid('prenotazione')->name('spazi.prenotazioni.destroy');

    // ---- f.9 Inventario attrezzature (CRUD + ricerca) ----
    Route::get('/attrezzature', [AttrezzaturaController::class, 'index'])->name('attrezzature.index');
    Route::post('/attrezzature', [AttrezzaturaController::class, 'store'])->name('attrezzature.store');
    Route::put('/attrezzature/{attrezzatura}', [AttrezzaturaController::class, 'update'])
        ->whereUuid('attrezzatura')->name('attrezzature.update');
    Route::delete('/attrezzature/{attrezzatura}', [AttrezzaturaController::class, 'destroy'])
        ->whereUuid('attrezzatura')->name('attrezzature.destroy');

    // ---- f.10 Prestiti attrezzature (disponibilità, restituzione, storico) ----
    Route::get('/prestiti', [PrestitoAttrezzaturaController::class, 'index'])->name('prestiti.index');
    Route::post('/prestiti', [PrestitoAttrezzaturaController::class, 'store'])->name('prestiti.store');
    Route::post('/prestiti/{prestito}/restituisci', [PrestitoAttrezzaturaController::class, 'restituisci'])
        ->whereUuid('prestito')->name('prestiti.restituisci');
    Route::delete('/prestiti/{prestito}', [PrestitoAttrezzaturaController::class, 'destroy'])
        ->whereUuid('prestito')->name('prestiti.destroy');
});
