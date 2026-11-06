<?php

use App\Http\Controllers\AreaSoci\ProposteController as ProposteSocioController;
use App\Http\Controllers\Struttura\PropostaController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: proposte dei soci (issue #10)
|--------------------------------------------------------------------------
|
| Area soci: proposte pubblicate, presentazione e adesioni.
| Gestionale: moderazione, inserimento nell'OdG, esito e impostazioni.
| Governance: PropostaPolicy, famiglia di permessi proposte.*.
|
*/

Route::middleware(['auth', 'tenant'])->prefix('proposte')->name('proposte.')->group(function () {
    Route::get('/', [PropostaController::class, 'index'])->name('index');
    Route::post('impostazioni', [PropostaController::class, 'impostazioni'])->name('impostazioni');
    Route::get('{proposta}', [PropostaController::class, 'show'])->name('show');
    Route::post('{proposta}/pubblica', [PropostaController::class, 'pubblica'])->name('pubblica');
    Route::post('{proposta}/non-ammettere', [PropostaController::class, 'nonAmmettere'])->name('non-ammettere');
    Route::post('{proposta}/odg', [PropostaController::class, 'inserisciInOdg'])->name('odg');
    Route::post('{proposta}/esito', [PropostaController::class, 'esito'])->name('esito');
});

Route::prefix('area-soci')
    ->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function (): void {
        Route::get('/proposte', [ProposteSocioController::class, 'index'])->name('proposte');
        Route::post('/proposte', [ProposteSocioController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('proposte.store');
        Route::post('/proposte/{proposta}/adesione', [ProposteSocioController::class, 'aderisci'])
            ->middleware('throttle:30,1')
            ->name('proposte.aderisci');
        Route::delete('/proposte/{proposta}/adesione', [ProposteSocioController::class, 'revoca'])
            ->middleware('throttle:30,1')
            ->name('proposte.revoca');
    });
