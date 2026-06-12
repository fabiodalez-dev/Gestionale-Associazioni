<?php

use App\Http\Controllers\EmailConferme\AnteprimaEmailController;
use App\Http\Controllers\EmailConferme\RicevutaAreaRiservataController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use App\Http\Middleware\FrontendPubblico\AutenticaAccount;
use App\Http\Middleware\FrontendPubblico\EnsureAccountValido;
use App\Http\Middleware\FrontendPubblico\EnsureAccountVerificato;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: email-conferme (f.18–21 PIANO-VITA-SOCIALE)
|--------------------------------------------------------------------------
|
| - Centro anteprime email (admin, auth + tenant): elenco notifiche di
|   sistema, anteprima su dati di esempio, invio di prova a sé stessi;
| - download ricevuta quota numerata dalle aree riservate (area soci con
|   guard 'socio', area account del mini-sito pubblico con guard 'account').
|   Il download admin riusa la rotta quote.ricevuta del modulo quote.
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/email/anteprime', [AnteprimaEmailController::class, 'index'])->name('email-anteprime.index');
    Route::get('/email/anteprime/{codice}', [AnteprimaEmailController::class, 'show'])
        ->where('codice', '[a-z0-9_]+')
        ->name('email-anteprime.show');
    Route::post('/email/anteprime/{codice}/prova', [AnteprimaEmailController::class, 'prova'])
        ->where('codice', '[a-z0-9_]+')
        ->middleware('throttle:10,1')
        ->name('email-anteprime.prova');
});

// Area soci: ricevuta della propria quota pagata
Route::prefix('area-soci')->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function () {
        Route::get('/quote/{quota}/ricevuta', [RicevutaAreaRiservataController::class, 'areaSoci'])
            ->name('quote.ricevuta');
    });

// Area account del frontend pubblico: ricevuta delle quote del socio collegato
Route::prefix('a/{associazione:slug}')->name('pubblico.')
    ->middleware(RisolviTenantPubblico::class)
    ->group(function (): void {
        Route::prefix('account')->name('account.')
            ->middleware([
                AutenticaAccount::class.':account',
                EnsureAccountValido::class,
                EnsureAccountVerificato::class,
            ])
            ->group(function (): void {
                Route::get('/quote/{quota}/ricevuta', [RicevutaAreaRiservataController::class, 'account'])
                    ->name('quote.ricevuta');
            });
    });
