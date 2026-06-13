<?php

use App\Http\Controllers\Admin\UpdateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Aggiornamenti applicazione (super_admin)
|--------------------------------------------------------------------------
| Pagina /aggiornamenti per controllare e applicare gli update in-app.
| L'accesso è ristretto al super_admin dal controller (isSuperAdmin),
| niente middleware 'tenant': l'update riguarda l'intera installazione.
*/
Route::middleware(['auth'])->group(function (): void {
    Route::get('/aggiornamenti', [UpdateController::class, 'index'])->name('aggiornamenti.index');
    Route::get('/aggiornamenti/controlla', [UpdateController::class, 'controlla'])->name('aggiornamenti.controlla');
    Route::post('/aggiornamenti/applica', [UpdateController::class, 'aggiorna'])
        ->middleware('throttle:3,10')
        ->name('aggiornamenti.applica');
});
