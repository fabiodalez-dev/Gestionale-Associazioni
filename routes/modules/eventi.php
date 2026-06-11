<?php

use App\Http\Controllers\Api\V1\EventoApiController;
use App\Http\Controllers\Eventi\CheckinPageController;
use App\Http\Controllers\Eventi\EventoController;
use App\Http\Controllers\Eventi\PartecipanteController;
use App\Http\Controllers\Eventi\PrenotazioneEventoController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Spatie\Csp\AddCspHeaders;

/*
|--------------------------------------------------------------------------
| Modulo: eventi
|--------------------------------------------------------------------------
|
| 1. Area admin (auth + tenant): CRUD eventi, gestione partecipanti, export.
| 2. Pagina pubblica /checkin: SPA mobile per il check-in QR (nessuna sessione,
|    parla solo con l'API; header di sicurezza propri → esclusi quelli globali).
| 3. API REST /api/v1: autenticazione con API key Bearer (chiavi scoped o
|    globali), permessi granulari eventi:read / eventi:write. CSRF escluso
|    (client senza sessione).
|
*/

// ---- Area amministrativa ----
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/eventi', [EventoController::class, 'index'])->name('eventi.index');
    Route::post('/eventi', [EventoController::class, 'store'])->name('eventi.store');
    Route::put('/eventi/{evento}', [EventoController::class, 'update'])->name('eventi.update');
    Route::delete('/eventi/{evento}', [EventoController::class, 'destroy'])->name('eventi.destroy');

    Route::get('/eventi/{evento}/partecipanti', [PartecipanteController::class, 'index'])
        ->name('eventi.partecipanti.index');
    Route::get('/eventi/{evento}/partecipanti/export', [PartecipanteController::class, 'export'])
        ->name('eventi.partecipanti.export');
    Route::post('/eventi/{evento}/partecipanti', [PartecipanteController::class, 'store'])
        ->name('eventi.partecipanti.store');
    Route::post('/eventi/{evento}/partecipanti/tutti', [PartecipanteController::class, 'storeAll'])
        ->name('eventi.partecipanti.storeAll');
    Route::put('/eventi/{evento}/partecipanti/{partecipante}', [PartecipanteController::class, 'update'])
        ->name('eventi.partecipanti.update');
    Route::delete('/eventi/{evento}/partecipanti/{partecipante}', [PartecipanteController::class, 'destroy'])
        ->name('eventi.partecipanti.destroy');

    // ---- Prenotazioni dal frontend pubblico (tab admin dell'evento) ----
    Route::get('/eventi/{evento}/prenotazioni', [PrenotazioneEventoController::class, 'index'])
        ->name('eventi.prenotazioni.index');
    Route::get('/eventi/{evento}/prenotazioni/export', [PrenotazioneEventoController::class, 'export'])
        ->name('eventi.prenotazioni.export');
    Route::post('/eventi/{evento}/prenotazioni/promuovi', [PrenotazioneEventoController::class, 'promuovi'])
        ->name('eventi.prenotazioni.promuovi');
    Route::post('/eventi/{evento}/prenotazioni/{prenotazione}/conferma-pagamento', [PrenotazioneEventoController::class, 'confermaPagamento'])
        ->name('eventi.prenotazioni.conferma-pagamento');
    Route::post('/eventi/{evento}/prenotazioni/{prenotazione}/annulla', [PrenotazioneEventoController::class, 'annulla'])
        ->name('eventi.prenotazioni.annulla');
    Route::post('/eventi/{evento}/prenotazioni/{prenotazione}/reinvia-biglietto', [PrenotazioneEventoController::class, 'reinviaBiglietto'])
        ->name('eventi.prenotazioni.reinvia');
});

// ---- Pagina pubblica check-in (senza login: usa l'API key) ----
Route::get('/checkin', CheckinPageController::class)
    ->withoutMiddleware([SecurityHeaders::class, AddCspHeaders::class])
    ->name('eventi.checkin');

// ---- API REST v1 (Bearer API key, niente CSRF/sessione) ----
Route::prefix('api/v1')
    ->withoutMiddleware([ValidateCsrfToken::class, AddCspHeaders::class])
    ->group(function () {
        Route::get('/', [EventoApiController::class, 'ping'])
            ->middleware(AuthenticateApiKey::class)
            ->name('api.v1.ping');

        Route::get('/eventi', [EventoApiController::class, 'index'])
            ->middleware(AuthenticateApiKey::class.':eventi:read')
            ->name('api.v1.eventi.index');

        Route::get('/eventi/{id}', [EventoApiController::class, 'show'])
            ->middleware(AuthenticateApiKey::class.':eventi:read')
            ->name('api.v1.eventi.show');

        Route::post('/eventi/{id}/checkin', [EventoApiController::class, 'checkin'])
            ->middleware(AuthenticateApiKey::class.':eventi:write')
            ->name('api.v1.eventi.checkin');
    });
