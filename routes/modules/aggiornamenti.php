<?php

use App\Http\Controllers\Admin\UpdateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Aggiornamenti applicazione (super_admin)
|--------------------------------------------------------------------------
| Pagina /aggiornamenti per controllare e applicare gli update in-app
| (dalla release GitHub o da un pacchetto caricato), seguire lo stato,
| scaricare i backup e sbloccare una manutenzione rimasta accesa.
| L'accesso è ristretto al super_admin dal controller (isSuperAdmin),
| niente middleware 'tenant': l'update riguarda l'intera installazione.
*/
Route::middleware(['auth'])->prefix('aggiornamenti')->name('aggiornamenti.')->group(function (): void {
    Route::get('/', [UpdateController::class, 'index'])->name('index');
    Route::get('/controlla', [UpdateController::class, 'controlla'])->name('controlla');
    Route::get('/stato', [UpdateController::class, 'stato'])->name('stato');
    Route::post('/applica', [UpdateController::class, 'aggiorna'])
        ->middleware('throttle:3,10')
        ->name('applica');
    Route::post('/carica', [UpdateController::class, 'carica'])
        ->middleware('throttle:3,10')
        ->name('carica');
    Route::post('/sblocca-manutenzione', [UpdateController::class, 'sbloccaManutenzione'])->name('sblocca');
    Route::post('/token', [UpdateController::class, 'salvaToken'])->name('token');
    Route::get('/backup/{nome}', [UpdateController::class, 'scaricaBackup'])
        ->where('nome', '[A-Za-z0-9._-]+')
        ->name('backup');
});
