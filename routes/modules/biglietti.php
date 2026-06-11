<?php

use App\Http\Controllers\Prenotazioni\CheckinBigliettoController;
use App\Http\Controllers\Prenotazioni\VerificaBigliettoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: biglietti (QR prenotazioni)
|--------------------------------------------------------------------------
|
| Verifica pubblica del biglietto via QR e check-in dallo scanner admin.
|
| - GET /verifica-biglietto/{token}: GLOBALE (fuori dal prefix a/{slug}),
|   rate-limited; il QR contiene l'URL firmato (la firma è validata nel
|   controller per restituire una pagina di esito amichevole, pattern
|   verifica-tessera). Nessun dato personale completo in pagina.
|
| - POST /biglietti/checkin: area admin (auth + tenant), risposta JSON per
|   lo scanner esteso (resources/views/tessere/checkin.blade.php).
|
*/

Route::get('/verifica-biglietto/{token}', VerificaBigliettoController::class)
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:30,1')
    ->name('biglietti.verifica');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::post('/biglietti/checkin', CheckinBigliettoController::class)
        ->name('biglietti.checkin');
});
