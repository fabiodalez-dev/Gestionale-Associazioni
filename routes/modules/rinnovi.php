<?php

use App\Http\Controllers\Rinnovi\AperturaAnnoController;
use App\Http\Controllers\Rinnovi\CampagnaRinnoviController;
use App\Http\Controllers\Rinnovi\RinnovoAccountController;
use App\Http\Controllers\Rinnovi\RinnovoSocioController;
use App\Http\Controllers\Rinnovi\StampaMassivaTessereController;
use App\Http\Controllers\Rinnovi\StoricoTesseramentoController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use App\Http\Middleware\FrontendPubblico\AutenticaAccount;
use App\Http\Middleware\FrontendPubblico\EnsureAccountValido;
use App\Http\Middleware\FrontendPubblico\EnsureAccountVerificato;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: rinnovi (sezione A del piano Vita sociale, f.1–f.6)
|--------------------------------------------------------------------------
|
| Area admin (auth + tenant):
| - campagna rinnovi dell'anno corrente (lista, contatori, invio massivo);
| - apertura anno sociale (anteprima + generazione bulk quote);
| - stampa massiva tessere in un unico PDF;
| - export Excel dello storico tesseramento del singolo socio.
|
| Area soci (guard 'socio') e area account del mini-sito pubblico
| (guard 'account', solo account collegati a un socio): avvio del rinnovo
| self-service → quota dell'anno + redirect alla pagina di pagamento
| pubblica (pagamento.show, payment_token).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    // f.2 — Campagna rinnovi {anno corrente}
    Route::get('/campagna-rinnovi', [CampagnaRinnoviController::class, 'index'])
        ->name('campagna-rinnovi.index');
    Route::post('/campagna-rinnovi/invia', [CampagnaRinnoviController::class, 'invia'])
        ->name('campagna-rinnovi.invia');

    // f.5 — Apertura anno sociale
    Route::get('/apertura-anno', [AperturaAnnoController::class, 'index'])
        ->name('apertura-anno.index');
    Route::post('/apertura-anno', [AperturaAnnoController::class, 'genera'])
        ->name('apertura-anno.genera');

    // f.4 — Stampa massiva tessere (GET idempotente come il PDF singolo)
    Route::get('/tessere-stampa-massiva', [StampaMassivaTessereController::class, 'pdf'])
        ->name('tessere.stampa-massiva');

    // f.6 — Export Excel storico tesseramento del singolo socio
    Route::get('/soci/{socio}/storico-tesseramento/export', [StoricoTesseramentoController::class, 'export'])
        ->whereUuid('socio')
        ->name('soci.storico-tesseramento.export');
});

// f.1 — Rinnovo self-service dall'area soci
Route::prefix('area-soci')->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function () {
        Route::post('/rinnova', [RinnovoSocioController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('rinnova');
    });

// f.1 — Rinnovo self-service dall'area account del mini-sito pubblico
Route::prefix('a/{associazione:slug}')->name('pubblico.account.')
    ->middleware([
        RisolviTenantPubblico::class,
        AutenticaAccount::class.':account',
        EnsureAccountValido::class,
        EnsureAccountVerificato::class,
    ])
    ->group(function () {
        Route::post('/account/rinnova', [RinnovoAccountController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('rinnova');
    });
