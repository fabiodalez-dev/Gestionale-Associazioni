<?php

use App\Http\Controllers\Struttura\AmministratoreController;
use App\Http\Controllers\Struttura\AssociazioneController;
use App\Http\Controllers\Struttura\ConfigurazioniController;
use App\Http\Controllers\Struttura\InvitoController;
use App\Http\Controllers\Struttura\PreiscrizioneController;
use App\Http\Controllers\Struttura\PrivacyPolicyController;
use App\Http\Controllers\Struttura\SedeController;
use App\Http\Controllers\Struttura\UtenteSearchController;
use Illuminate\Support\Facades\Route;
use Spatie\Honeypot\ProtectAgainstSpam;

/*
|--------------------------------------------------------------------------
| Modulo: struttura
|--------------------------------------------------------------------------
|
| Gestione associazioni (solo super_admin), sedi, utenti amministratori,
| configurazioni tenant, pre-iscrizione pubblica e privacy policy pubblica.
| Spec: docs/specs/struttura-admin.md
|
*/

/*
 * Rotte PUBBLICHE (nessun login): preiscrizione, privacy policy, invito admin.
 */
Route::get('/preiscrizione/{associazione}', [PreiscrizioneController::class, 'show'])
    ->name('preiscrizione.show');

Route::post('/preiscrizione/{associazione}', [PreiscrizioneController::class, 'store'])
    ->middleware([ProtectAgainstSpam::class, 'throttle:10,1']) // honeypot (miglioramento 6) + rate limit (miglioramento 4)
    ->name('preiscrizione.store');

Route::get('/privacy-policy/{associazione}', PrivacyPolicyController::class)
    ->name('privacy-policy.show');

// Accettazione invito amministratore: solo tramite link firmato temporaneo
Route::get('/invito/{utente}', [InvitoController::class, 'show'])
    ->middleware('signed')
    ->name('invito.show');

Route::post('/invito/{utente}', [InvitoController::class, 'store'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('invito.store');

/*
 * Rotte AUTENTICATE.
 */
Route::middleware(['auth'])->group(function () {
    // Gestione associazioni — SOLO super_admin (policy), nessun middleware tenant
    Route::get('/associazioni', [AssociazioneController::class, 'index'])->name('associazioni.index');
    Route::post('/associazioni', [AssociazioneController::class, 'store'])->name('associazioni.store');
    Route::put('/associazioni/{associazione}', [AssociazioneController::class, 'update'])->name('associazioni.update');
    Route::delete('/associazioni/{associazione}', [AssociazioneController::class, 'destroy'])->name('associazioni.destroy');
    Route::post('/associazioni/{associazione}/rimuovi-admin', [AssociazioneController::class, 'removeAdmin'])->name('associazioni.remove-admin');

    // API ricerca utenti per autocomplete "collega admin" — solo super_admin
    Route::get('/api/utenti-search', UtenteSearchController::class)->name('utenti.search');

    // Utenti amministratori — SOLO super_admin (policy)
    Route::get('/amministratori', [AmministratoreController::class, 'index'])->name('amministratori.index');
    Route::post('/amministratori', [AmministratoreController::class, 'store'])->name('amministratori.store');
    Route::put('/amministratori/{utente}', [AmministratoreController::class, 'update'])->name('amministratori.update');
    Route::delete('/amministratori/{utente}', [AmministratoreController::class, 'destroy'])->name('amministratori.destroy');
    Route::post('/amministratori/{utente}/reinvia-invito', [AmministratoreController::class, 'reinviaInvito'])->name('amministratori.reinvia-invito');

    // Configurazioni tenant: richiedono il contesto associazione
    Route::middleware(['tenant'])->group(function () {
        Route::get('/configurazioni', [ConfigurazioniController::class, 'index'])->name('configurazioni.index');
        Route::post('/configurazioni/info', [ConfigurazioniController::class, 'updateInfo'])->name('configurazioni.info');
        Route::post('/configurazioni/logo', [ConfigurazioniController::class, 'uploadLogo'])->name('configurazioni.logo');
        Route::post('/configurazioni/tesseramento', [ConfigurazioniController::class, 'updateTesseramento'])->name('configurazioni.tesseramento');
        Route::post('/configurazioni/template-email', [ConfigurazioniController::class, 'updateTemplateEmail'])->name('configurazioni.template-email');
        Route::post('/configurazioni/privacy-policy', [ConfigurazioniController::class, 'updatePrivacyPolicy'])->name('configurazioni.privacy-policy');
        Route::post('/configurazioni/campi-obbligatori', [ConfigurazioniController::class, 'updateCampiObbligatori'])->name('configurazioni.campi-obbligatori');
        Route::post('/configurazioni/tessera-template', [ConfigurazioniController::class, 'saveTesseraTemplate'])->name('configurazioni.tessera-template');
        Route::post('/configurazioni/pagamenti', [ConfigurazioniController::class, 'updatePaymentGateway'])->name('configurazioni.pagamenti');

        // Sedi (CRUD dentro configurazioni — spec §2.2)
        Route::post('/configurazioni/sedi', [SedeController::class, 'save'])->name('sedi.save');
        Route::delete('/configurazioni/sedi/{sede}', [SedeController::class, 'destroy'])->name('sedi.destroy');

        // Redirect legacy: /sezioni → configurazioni sezione sedi (spec §2.1, §9.17)
        Route::get('/sezioni', fn () => redirect()->route('configurazioni.index', ['section' => 'sedi']))
            ->name('sezioni.index');
    });
});
