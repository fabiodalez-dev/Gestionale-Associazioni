<?php

use App\Http\Controllers\Comunicazione\AnnuncioController;
use App\Http\Controllers\Comunicazione\BachecaSocioController;
use App\Http\Controllers\Comunicazione\ConfigurazioniComunicazioneController;
use App\Http\Controllers\Comunicazione\MessaggioSocioController;
use App\Http\Controllers\Comunicazione\NewsletterController;
use App\Http\Controllers\Comunicazione\NovitaPubblicheController;
use App\Http\Controllers\Comunicazione\SondaggiAccountController;
use App\Http\Controllers\Comunicazione\SondaggioController;
use App\Http\Controllers\Comunicazione\SondaggiSocioController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use App\Http\Middleware\FrontendPubblico\AutenticaAccount;
use App\Http\Middleware\FrontendPubblico\EnsureAccountValido;
use App\Http\Middleware\FrontendPubblico\EnsureAccountVerificato;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: comunicazione (piano Gestione 2, sezione B — f.7–f.12)
|--------------------------------------------------------------------------
|
| 1. Area admin (auth + tenant): bacheca annunci (f.7), newsletter da
|    annunci (f.8), sondaggi con risultati (f.11), toggle auguri di
|    compleanno (f.9), email diretta al socio dalla scheda (f.12).
| 2. Mini-sito pubblico (/a/{slug}, RisolviTenantPubblico): sezione
|    "Novità" (annunci pubblicati non solo-soci) + voto sondaggi
|    dall'area riservata account (guard 'account').
| 3. Area soci (guard 'socio'): bacheca completa (inclusi i solo_soci)
|    e voto sondaggi.
|
*/

// ---- Area amministrativa ----
Route::middleware(['auth', 'tenant'])->group(function (): void {
    // f.7 — bacheca annunci (CRUD)
    Route::get('/annunci', [AnnuncioController::class, 'index'])->name('annunci.index');
    Route::get('/annunci/nuovo', [AnnuncioController::class, 'create'])->name('annunci.create');
    Route::post('/annunci', [AnnuncioController::class, 'store'])->name('annunci.store');
    Route::get('/annunci/{annuncio}/modifica', [AnnuncioController::class, 'edit'])->name('annunci.edit');
    Route::put('/annunci/{annuncio}', [AnnuncioController::class, 'update'])->name('annunci.update');
    Route::delete('/annunci/{annuncio}', [AnnuncioController::class, 'destroy'])->name('annunci.destroy');

    // f.8 — newsletter composta da annunci pubblicati + storico invii
    Route::get('/newsletter', [NewsletterController::class, 'index'])->name('newsletter.index');
    Route::post('/newsletter/anteprima', [NewsletterController::class, 'anteprima'])->name('newsletter.anteprima');
    Route::post('/newsletter', [NewsletterController::class, 'store'])->name('newsletter.store');

    // f.9 — toggle auguri di compleanno automatici (configurazioni)
    Route::post('/configurazioni/comunicazione', [ConfigurazioniComunicazioneController::class, 'update'])
        ->name('configurazioni.comunicazione');

    // f.11 — sondaggi (CRUD + risultati aggregati)
    Route::get('/sondaggi', [SondaggioController::class, 'index'])->name('sondaggi.index');
    Route::get('/sondaggi/nuovo', [SondaggioController::class, 'create'])->name('sondaggi.create');
    Route::post('/sondaggi', [SondaggioController::class, 'store'])->name('sondaggi.store');
    Route::get('/sondaggi/{sondaggio}', [SondaggioController::class, 'show'])->name('sondaggi.show');
    Route::get('/sondaggi/{sondaggio}/modifica', [SondaggioController::class, 'edit'])->name('sondaggi.edit');
    Route::put('/sondaggi/{sondaggio}', [SondaggioController::class, 'update'])->name('sondaggi.update');
    Route::delete('/sondaggi/{sondaggio}', [SondaggioController::class, 'destroy'])->name('sondaggi.destroy');

    // f.12 — email diretta al socio dalla scheda (modale oggetto+corpo)
    Route::post('/soci/{socio}/invia-email', [MessaggioSocioController::class, 'store'])
        ->name('soci.invia-email');
});

// ---- Mini-sito pubblico (/a/{slug}) ----
Route::prefix('a/{associazione:slug}')
    ->name('pubblico.')
    ->middleware(RisolviTenantPubblico::class)
    ->group(function (): void {
        // f.7 — elenco pubblico "Novità" (annunci pubblicati non solo-soci)
        Route::get('/novita', [NovitaPubblicheController::class, 'index'])->name('novita');

        // f.11 — sondaggi votabili dall'area riservata account
        Route::prefix('account')
            ->name('account.')
            ->middleware([
                AutenticaAccount::class.':account',
                EnsureAccountValido::class,
                EnsureAccountVerificato::class,
            ])->group(function (): void {
                Route::get('/sondaggi', [SondaggiAccountController::class, 'index'])->name('sondaggi');
                Route::post('/sondaggi/{sondaggio}/vota', [SondaggiAccountController::class, 'vota'])
                    ->middleware('throttle:20,1')
                    ->name('sondaggi.vota');
            });
    });

// ---- Area soci (guard 'socio') ----
Route::prefix('area-soci')
    ->name('area-soci.')
    ->middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])
    ->group(function (): void {
        // f.7 — bacheca completa (inclusi gli annunci solo_soci)
        Route::get('/bacheca', [BachecaSocioController::class, 'index'])->name('bacheca');

        // f.11 — sondaggi aperti + voto
        Route::get('/sondaggi', [SondaggiSocioController::class, 'index'])->name('sondaggi');
        Route::post('/sondaggi/{sondaggio}/vota', [SondaggiSocioController::class, 'vota'])
            ->middleware('throttle:20,1')
            ->name('sondaggi.vota');
    });
