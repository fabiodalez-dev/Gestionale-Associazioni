<?php

use App\Http\Controllers\AnagraficaPro\DuplicatiController;
use App\Http\Controllers\AnagraficaPro\ExportImportController;
use App\Http\Controllers\AnagraficaPro\InvitoQrController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: anagrafica-pro (funzionalità 22–25 PIANO-VITA-SOCIALE)
|--------------------------------------------------------------------------
|
| f.22 QR PNG del link di invito /a/{slug}/iscriviti (il box "Invita al
|      tuo mini-sito" vive nelle Configurazioni, sezione "invito");
| f.24 pagina "Possibili duplicati" + merge assistito;
| f.25 export Excel multi-foglio + import di aggiornamento con anteprima diff.
|
| Autorizzazione: SocioPolicy (duplicati/export-import) e
| AssociazionePolicy::manageSettings (QR invito), dentro i controller.
|
*/

Route::middleware(['auth', 'tenant'])->group(function (): void {
    // ── f.22: QR PNG dell'invito al mini-sito (inline o ?download=1) ────
    Route::get('/configurazioni/invito-qr', InvitoQrController::class)
        ->name('configurazioni.invito-qr');

    // ── f.24: possibili duplicati + unione assistita ────────────────────
    Route::get('/soci-duplicati', [DuplicatiController::class, 'index'])
        ->name('soci-duplicati.index');
    Route::post('/soci-duplicati/unisci', [DuplicatiController::class, 'merge'])
        ->name('soci-duplicati.unisci');

    // ── f.25: export multi-foglio + import aggiornamento con diff ───────
    Route::get('/export-import', [ExportImportController::class, 'index'])
        ->name('export-import.index');
    Route::get('/export-import/export', [ExportImportController::class, 'export'])
        ->name('export-import.export');
    Route::post('/export-import/import/anteprima', [ExportImportController::class, 'anteprima'])
        ->name('export-import.import.anteprima');
    Route::post('/export-import/import/applica', [ExportImportController::class, 'applica'])
        ->name('export-import.import.applica');
});
