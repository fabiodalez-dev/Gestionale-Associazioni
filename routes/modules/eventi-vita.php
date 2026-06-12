<?php

use App\Http\Controllers\EventiVita\CalendarioEventiController;
use App\Http\Controllers\EventiVita\CalendarioPubblicoController;
use App\Http\Controllers\EventiVita\EventoRicorrenteController;
use App\Http\Controllers\EventiVita\FeedbackEventoController;
use App\Http\Controllers\EventiVita\FeedbackPubblicoController;
use App\Http\Controllers\EventiVita\PresenzeController;
use App\Http\Controllers\EventiVita\SerieEventoController;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: eventi-vita (piano Vita sociale, sezione B — f.7–f.12)
|--------------------------------------------------------------------------
|
| 1. Area admin (auth + tenant): calendario mensile, eventi ricorrenti
|    (serie), presenze manuali + foglio presenze PDF, report feedback,
|    export .ics del singolo evento.
| 2. Mini-sito pubblico (/a/{slug}, RisolviTenantPubblico): calendario
|    mensile dei pubblicati, feed calendario.ics, .ics del singolo evento.
| 3. Form pubblico di feedback post-evento: GET dal link FIRMATO
|    dell'email (middleware signed), POST con throttle; il token 64 hex
|    è il capability token della prenotazione.
|
*/

// ---- Area amministrativa ----
Route::middleware(['auth', 'tenant'])->group(function (): void {
    // f.7 — calendario mensile admin + export ICS del singolo evento
    Route::get('/calendario-eventi', [CalendarioEventiController::class, 'index'])
        ->name('calendario-eventi.index');
    Route::get('/eventi/{evento}/evento.ics', [CalendarioEventiController::class, 'ics'])
        ->name('eventi.ics');

    // f.8 — eventi ricorrenti: creazione serie e gestione dell'intera serie
    Route::get('/eventi-ricorrenti/nuovo', [EventoRicorrenteController::class, 'create'])
        ->name('eventi-ricorrenti.create');
    Route::post('/eventi-ricorrenti', [EventoRicorrenteController::class, 'store'])
        ->name('eventi-ricorrenti.store');

    Route::get('/eventi/{evento}/serie', [SerieEventoController::class, 'show'])
        ->name('eventi.serie.show');
    Route::put('/eventi/{evento}/serie', [SerieEventoController::class, 'update'])
        ->name('eventi.serie.update');
    Route::delete('/eventi/{evento}/serie', [SerieEventoController::class, 'destroy'])
        ->name('eventi.serie.destroy');

    // f.9 — presenze manuali in blocco + foglio presenze PDF
    Route::get('/eventi/{evento}/presenze', [PresenzeController::class, 'index'])
        ->name('eventi.presenze.index');
    Route::post('/eventi/{evento}/presenze', [PresenzeController::class, 'store'])
        ->name('eventi.presenze.store');
    Route::get('/eventi/{evento}/foglio-presenze.pdf', [PresenzeController::class, 'foglio'])
        ->name('eventi.presenze.foglio');

    // f.11 — report feedback dell'evento
    Route::get('/eventi/{evento}/feedback', [FeedbackEventoController::class, 'index'])
        ->name('eventi.feedback.index');
});

// ---- Mini-sito pubblico (/a/{slug}) ----
Route::prefix('a/{associazione:slug}')
    ->name('pubblico.')
    ->middleware(RisolviTenantPubblico::class)
    ->group(function (): void {
        Route::get('/calendario', [CalendarioPubblicoController::class, 'show'])
            ->name('calendario');
        Route::get('/calendario.ics', [CalendarioPubblicoController::class, 'feed'])
            ->name('calendario.ics');
        Route::get('/eventi/{evento}/evento.ics', [CalendarioPubblicoController::class, 'evento'])
            ->name('eventi.ics');
    });

// ---- Form pubblico di feedback post-evento (fuori dal contesto tenant) ----
Route::get('/feedback-eventi/{token}', [FeedbackPubblicoController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->where('token', '[a-f0-9]{64}')
    ->name('feedback-eventi.show');

Route::post('/feedback-eventi/{token}', [FeedbackPubblicoController::class, 'store'])
    ->middleware('throttle:10,1')
    ->where('token', '[a-f0-9]{64}')
    ->name('feedback-eventi.store');
