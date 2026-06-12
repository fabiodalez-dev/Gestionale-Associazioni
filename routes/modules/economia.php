<?php

use App\Http\Controllers\Economia\DonazioneAdminController;
use App\Http\Controllers\Economia\DonazionePubblicaController;
use App\Http\Controllers\Economia\EconomiaEventoController;
use App\Http\Controllers\Economia\PrimaNotaController;
use App\Http\Controllers\Economia\ReportEconomicoController;
use App\Http\Controllers\Economia\ScadenzarioController;
use App\Http\Controllers\Economia\SollecitoQuoteController;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use Illuminate\Support\Facades\Route;
use Spatie\Honeypot\ProtectAgainstSpam;

/*
|--------------------------------------------------------------------------
| Modulo: economia (piano "Gestione 2", sezione C — f.13–f.18)
|--------------------------------------------------------------------------
|
| 1. Area admin (auth + tenant): prima nota con saldo progressivo (f.13),
|    report economico con export Excel (f.14), elenco donazioni (f.15),
|    solleciti quote singolo/bulk (f.16), economia evento con budget e
|    spese (f.17), scadenzario unificato (f.18).
| 2. Mini-sito pubblico (/a/{slug}, RisolviTenantPubblico): pagina
|    donazioni con checkout Stripe/PayPal (f.15). La conferma arriva
|    SEMPRE dai webhook già esistenti (dispatch tipo 'donazione').
|
*/

// ---- Area amministrativa ----
Route::middleware(['auth', 'tenant'])->group(function (): void {
    // f.13 — prima nota
    Route::get('/prima-nota', [PrimaNotaController::class, 'index'])->name('prima-nota.index');
    Route::post('/prima-nota', [PrimaNotaController::class, 'store'])->name('prima-nota.store');
    Route::put('/prima-nota/{movimento}', [PrimaNotaController::class, 'update'])->name('prima-nota.update');
    Route::delete('/prima-nota/{movimento}', [PrimaNotaController::class, 'destroy'])->name('prima-nota.destroy');

    // f.14 — report economico
    Route::get('/report-economico', [ReportEconomicoController::class, 'index'])->name('report-economico.index');
    Route::get('/report-economico/export', [ReportEconomicoController::class, 'export'])->name('report-economico.export');

    // f.15 — elenco donazioni
    Route::get('/donazioni', [DonazioneAdminController::class, 'index'])->name('donazioni.index');

    // f.16 — solleciti quote non pagate
    Route::get('/solleciti-quote', [SollecitoQuoteController::class, 'index'])->name('solleciti-quote.index');
    Route::post('/solleciti-quote/bulk', [SollecitoQuoteController::class, 'inviaBulk'])->name('solleciti-quote.bulk');
    Route::post('/solleciti-quote/{quota}', [SollecitoQuoteController::class, 'invia'])->name('solleciti-quote.invia');

    // f.17 — economia evento (budget + spese vs incassi)
    Route::get('/eventi/{evento}/economia', [EconomiaEventoController::class, 'show'])->name('eventi.economia.show');
    Route::put('/eventi/{evento}/economia/budget', [EconomiaEventoController::class, 'aggiornaBudget'])->name('eventi.economia.budget');
    Route::post('/eventi/{evento}/economia/spese', [EconomiaEventoController::class, 'storeSpesa'])->name('eventi.economia.spese.store');
    Route::delete('/economia/spese-evento/{spesa}', [EconomiaEventoController::class, 'destroySpesa'])->name('eventi.economia.spese.destroy');

    // f.18 — scadenzario unificato
    Route::get('/scadenzario', [ScadenzarioController::class, 'index'])->name('scadenzario.index');
});

// ---- Donazioni dal mini-sito pubblico (/a/{slug}/dona) ----
Route::prefix('a/{associazione:slug}')
    ->name('pubblico.')
    ->middleware(RisolviTenantPubblico::class)
    ->group(function (): void {
        Route::get('/dona', [DonazionePubblicaController::class, 'show'])->name('dona.show');

        Route::post('/dona', [DonazionePubblicaController::class, 'store'])
            ->middleware([ProtectAgainstSpam::class, 'throttle:10,1'])
            ->name('dona.store');

        Route::get('/dona/{donazione}/esito', [DonazionePubblicaController::class, 'esito'])->name('dona.esito');
    });
