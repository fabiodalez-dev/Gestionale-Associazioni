<?php

use App\Http\Controllers\VolontariTurni\AreaSociVolontariatoController;
use App\Http\Controllers\VolontariTurni\MansioneController;
use App\Http\Controllers\VolontariTurni\RegistroOreController;
use App\Http\Controllers\VolontariTurni\TurnoController;
use App\Http\Controllers\VolontariTurni\VolontarioController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: volontari-turni (sezione A del piano "Organizzazione 3", f.1–6)
|--------------------------------------------------------------------------
|
| f.1 Profilo volontario del socio (disponibilità + flag) — scheda admin e
|     area riservata del socio.
| f.2 Mansioni/competenze: tassonomia per tenant + pagina volontari con
|     filtro per mansione.
| f.3 Turni: pianificazione admin (lista + per evento) con assegnazione
|     volontari suggeriti per mansione e disponibilità.
| f.4 Auto-candidatura del socio (area soci/account) con conferma o
|     auto_conferma e rinuncia con motivo.
| f.5 Registro ore: presenze a fine turno, ore manuali, attestato PDF.
| f.6 Comando turni:promemoria (NON registrato qui: lo fa Rifiniture).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    // ---- f.2 Mansioni (configurazione tassonomia) ----
    Route::get('/mansioni', [MansioneController::class, 'index'])->name('mansioni.index');
    Route::post('/mansioni', [MansioneController::class, 'store'])->name('mansioni.store');
    Route::put('/mansioni/{mansione}', [MansioneController::class, 'update'])->name('mansioni.update');
    Route::delete('/mansioni/{mansione}', [MansioneController::class, 'destroy'])->name('mansioni.destroy');

    // ---- f.1/f.2 Volontari (elenco con filtro mansione + profilo da scheda socio) ----
    Route::get('/volontari', [VolontarioController::class, 'index'])->name('volontari.index');
    Route::put('/volontari/{socio}/profilo', [VolontarioController::class, 'update'])->name('volontari.profilo.update');

    // ---- f.3 Turni: pianificazione admin ----
    Route::get('/turni', [TurnoController::class, 'index'])->name('turni.index');
    Route::get('/turni/crea', [TurnoController::class, 'create'])->name('turni.create');
    Route::post('/turni', [TurnoController::class, 'store'])->name('turni.store');
    Route::get('/turni/{turno}', [TurnoController::class, 'show'])->name('turni.show');
    Route::get('/turni/{turno}/modifica', [TurnoController::class, 'edit'])->name('turni.edit');
    Route::put('/turni/{turno}', [TurnoController::class, 'update'])->name('turni.update');
    Route::delete('/turni/{turno}', [TurnoController::class, 'destroy'])->name('turni.destroy');

    // f.3 Assegnazione volontari + gestione candidature
    Route::post('/turni/{turno}/assegna', [TurnoController::class, 'assegna'])->name('turni.assegna');
    Route::post('/turni/{turno}/assegnazioni/{assegnazione}/conferma', [TurnoController::class, 'conferma'])->name('turni.conferma');
    Route::delete('/turni/{turno}/assegnazioni/{assegnazione}', [TurnoController::class, 'rimuovi'])->name('turni.rimuovi');

    // f.5 Presenze a fine turno
    Route::post('/turni/{turno}/presenze', [TurnoController::class, 'registraPresenze'])->name('turni.presenze');

    // ---- f.5 Registro ore volontariato ----
    Route::get('/registro-ore', [RegistroOreController::class, 'index'])->name('registro-ore.index');
    Route::post('/registro-ore/manuali', [RegistroOreController::class, 'storeManuali'])->name('registro-ore.manuali');
    Route::delete('/registro-ore/{assegnazione}', [RegistroOreController::class, 'destroy'])->name('registro-ore.destroy');
    Route::get('/soci/{socio}/attestato-volontariato', [RegistroOreController::class, 'attestato'])->name('registro-ore.attestato');
});

/*
|--------------------------------------------------------------------------
| Area soci (guard 'socio' + tenant): profilo volontario e auto-candidatura
|--------------------------------------------------------------------------
| f.1 disponibilità self-service, f.4 candidatura/rinuncia, f.5 ore consultabili.
*/
Route::prefix('area-soci')->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function () {
        Route::get('/volontariato', [AreaSociVolontariatoController::class, 'index'])->name('volontariato');
        Route::patch('/volontariato/disponibilita', [AreaSociVolontariatoController::class, 'aggiornaDisponibilita'])->name('volontariato.disponibilita');
        Route::post('/volontariato/turni/{turno}/candidati', [AreaSociVolontariatoController::class, 'candidati'])->name('volontariato.candidati');
        Route::post('/volontariato/assegnazioni/{assegnazione}/rinuncia', [AreaSociVolontariatoController::class, 'rinuncia'])->name('volontariato.rinuncia');
    });
