<?php

use App\Http\Controllers\Email\ComunicazioniController;
use App\Http\Controllers\Email\EmailCodaController;
use App\Http\Controllers\Email\EmailImpostazioniController;
use App\Http\Controllers\Email\EmailLogController;
use App\Http\Controllers\Email\EmailTemplateController;
use App\Http\Controllers\Email\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: email & notifiche
|--------------------------------------------------------------------------
|
| Rotte amministrative (auth + tenant): comunicazioni massive, template,
| coda di invio, storico, impostazioni SMTP.
| Rotta pubblica (senza auth): disiscrizione con token per socio.
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    // Invia Comunicazione (invio massivo)
    Route::get('/comunicazioni', [ComunicazioniController::class, 'index'])->name('comunicazioni.index');
    Route::post('/comunicazioni', [ComunicazioniController::class, 'store'])->name('comunicazioni.store');
    Route::get('/comunicazioni/conteggio-destinatari', [ComunicazioniController::class, 'count'])->name('comunicazioni.count');

    // Template email (solo gli 8 predefiniti: niente create/delete)
    Route::get('/email/templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
    Route::put('/email/templates/{codice}', [EmailTemplateController::class, 'update'])
        ->where('codice', '[a-z0-9_]+')
        ->name('email-templates.update');

    // Coda di invio
    Route::get('/email/coda', [EmailCodaController::class, 'index'])->name('email-coda.index');
    Route::post('/email/coda/elabora', [EmailCodaController::class, 'process'])->name('email-coda.process');
    Route::post('/email/coda/annulla-pendenti', [EmailCodaController::class, 'cancelPending'])->name('email-coda.cancel-pending');
    Route::post('/email/coda/{item}/annulla', [EmailCodaController::class, 'cancel'])->name('email-coda.cancel');
    Route::post('/email/coda/{item}/riprova', [EmailCodaController::class, 'retry'])->name('email-coda.retry');

    // Storico email
    Route::get('/email/log', [EmailLogController::class, 'index'])->name('email-log.index');
    Route::get('/email/log/{log}', [EmailLogController::class, 'show'])->name('email-log.show');

    // Impostazioni SMTP
    Route::get('/email/impostazioni', [EmailImpostazioniController::class, 'index'])->name('email-impostazioni.index');
    Route::post('/email/impostazioni', [EmailImpostazioniController::class, 'store'])->name('email-impostazioni.store');
    Route::post('/email/impostazioni/test', [EmailImpostazioniController::class, 'test'])->name('email-impostazioni.test');
});

// Disiscrizione pubblica (token per socio, double opt-out GET+POST)
Route::get('/email/unsubscribe/{socio}/{token}', [UnsubscribeController::class, 'show'])->name('email.unsubscribe.show');
Route::post('/email/unsubscribe/{socio}/{token}', [UnsubscribeController::class, 'confirm'])->name('email.unsubscribe.confirm');
