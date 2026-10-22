<?php

use App\Http\Controllers\Admin\ImpostazioniSistemaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Impostazioni di sistema (super_admin)
|--------------------------------------------------------------------------
| Modifica del file .env dall'interfaccia: applicazione, SMTP di sistema,
| sicurezza accessi, database, backup e aggiornamenti. Accesso ristretto al
| super_admin dal controller (isSuperAdmin), come /aggiornamenti.
*/
Route::middleware(['auth'])->prefix('impostazioni-sistema')->name('impostazioni-sistema.')->group(function (): void {
    Route::match(['post', 'put'], '/email/test', [ImpostazioniSistemaController::class, 'testEmail'])
        ->middleware('throttle:5,1')
        ->name('test-email');
    Route::match(['post', 'put'], '/database/test', [ImpostazioniSistemaController::class, 'testDatabase'])
        ->middleware('throttle:10,1')
        ->name('test-database');
    Route::post('/sicurezza/revoca-accessi', [ImpostazioniSistemaController::class, 'revocaAccessi'])->name('revoca-accessi');
    Route::post('/sicurezza/azzera-tentativi', [ImpostazioniSistemaController::class, 'azzeraTentativi'])->name('azzera-tentativi');
    Route::get('/{sezione?}', [ImpostazioniSistemaController::class, 'index'])->name('index');
    Route::put('/{sezione}', [ImpostazioniSistemaController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('update');
});
