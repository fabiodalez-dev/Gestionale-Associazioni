<?php

use App\Http\Controllers\Iscrizioni\RichiestaIscrizioneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: iscrizioni (lato admin)
|--------------------------------------------------------------------------
|
| Gestione delle richieste di iscrizione socio inviate dal form pubblico
| /a/{slug}/iscriviti (docs/PIANO-ISCRIZIONI.md): lista pendenti + storico,
| approvazione (socio → Attivo, account collegato, tessera opzionale) e
| rifiuto con motivo obbligatorio. Autorizzazione granulare via
| RichiestaIscrizionePolicy (permessi soci.view / soci.update).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/richieste-iscrizione', [RichiestaIscrizioneController::class, 'index'])
        ->name('richieste-iscrizione.index');
    Route::post('/richieste-iscrizione/{richiesta}/approva', [RichiestaIscrizioneController::class, 'approva'])
        ->name('richieste-iscrizione.approva');
    Route::post('/richieste-iscrizione/{richiesta}/rifiuta', [RichiestaIscrizioneController::class, 'rifiuta'])
        ->name('richieste-iscrizione.rifiuta');
});
