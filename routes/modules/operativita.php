<?php

use App\Http\Controllers\Operativita\AttivitaAdminController;
use App\Http\Controllers\Operativita\AzioniBloccoSociController;
use App\Http\Controllers\Operativita\NotaSocioController;
use App\Http\Controllers\Operativita\RegistroModificheController;
use App\Http\Controllers\Operativita\RicercaGlobaleController;
use App\Http\Controllers\Operativita\VisteSalvateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: operativita (sezione A del piano "Gestione 2", f.1–f.6)
|--------------------------------------------------------------------------
|
| f.1 Ricerca globale (⌘K): endpoint JSON throttled e tenant-scoped per la
|     command palette (soci via Scout, LIKE per il resto, pagine admin).
| f.2 Azioni in blocco sulla lista soci (stato/tipo/categoria/sede, tag,
|     export selezione) con audit nello storico di ogni socio.
| f.3 Note interne sulla scheda socio (timeline con pin).
| f.4 Attività/promemoria admin (pagina + widget dashboard).
| f.5 Viste salvate per tessere/quote/prenotazioni evento (SavedFilter).
| f.6 Registro modifiche: UI sul log spatie/activitylog (struttura.view).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    // ---- f.1 Ricerca globale (command palette ⌘K) ----
    Route::get('/ricerca-globale', RicercaGlobaleController::class)
        ->middleware('throttle:30,1')
        ->name('ricerca-globale');

    // ---- f.2 Azioni in blocco sulla lista soci ----
    Route::post('/soci/azioni-blocco', [AzioniBloccoSociController::class, 'applica'])
        ->name('soci.azioni-blocco');
    Route::post('/soci/azioni-blocco/export', [AzioniBloccoSociController::class, 'export'])
        ->name('soci.azioni-blocco.export');

    // ---- f.3 Note interne sul socio ----
    Route::post('/soci/{socio}/note', [NotaSocioController::class, 'store'])
        ->name('soci.note.store');
    Route::patch('/soci/{socio}/note/{nota}/pin', [NotaSocioController::class, 'togglePin'])
        ->name('soci.note.pin');
    Route::delete('/soci/{socio}/note/{nota}', [NotaSocioController::class, 'destroy'])
        ->name('soci.note.destroy');

    // ---- f.4 Attività/promemoria admin ----
    Route::get('/attivita-admin', [AttivitaAdminController::class, 'index'])
        ->name('attivita-admin.index');
    Route::post('/attivita-admin', [AttivitaAdminController::class, 'store'])
        ->name('attivita-admin.store');
    Route::put('/attivita-admin/{attivita}', [AttivitaAdminController::class, 'update'])
        ->name('attivita-admin.update');
    Route::post('/attivita-admin/{attivita}/toggle', [AttivitaAdminController::class, 'toggle'])
        ->name('attivita-admin.toggle');
    Route::delete('/attivita-admin/{attivita}', [AttivitaAdminController::class, 'destroy'])
        ->name('attivita-admin.destroy');

    // ---- f.5 Viste salvate (tessere / quote / prenotazioni evento) ----
    Route::post('/viste-salvate/{scope}', [VisteSalvateController::class, 'store'])
        ->name('viste-salvate.store');
    Route::delete('/viste-salvate/{filtro}', [VisteSalvateController::class, 'destroy'])
        ->name('viste-salvate.destroy');

    // ---- f.6 Registro modifiche (UI per spatie/activitylog) ----
    Route::get('/registro-modifiche', RegistroModificheController::class)
        ->name('registro-modifiche.index');
});
