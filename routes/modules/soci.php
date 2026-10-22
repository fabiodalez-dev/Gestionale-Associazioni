<?php

use App\Http\Controllers\Privacy\DatiPersonaliController;
use App\Http\Controllers\Soci\CampoPersonalizzatoController;
use App\Http\Controllers\Soci\CategoriaSocioController;
use App\Http\Controllers\Soci\DocumentoSocioController;
use App\Http\Controllers\Soci\FotoSocioController;
use App\Http\Controllers\Soci\GruppoDinamicoController;
use App\Http\Controllers\Soci\SavedFilterController;
use App\Http\Controllers\Soci\SocioController;
use App\Http\Controllers\Soci\SocioStatoController;
use App\Http\Controllers\Soci\TagController;
use App\Http\Controllers\Soci\TipoSocioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: soci
|--------------------------------------------------------------------------
|
| Anagrafica soci (CRUD, filtri, preset, import/export, cestino, stati
| pre-iscrizione, documenti) + configurazioni (tipi, categorie, campi
| personalizzati, tag, gruppi dinamici). Autorizzazione granulare via
| Policy: la gestione soci è aperta anche all'operatore, le pagine di
| configurazione sono riservate ad admin_associazione/super_admin.
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    // ── Anagrafica soci ─────────────────────────────────────────────────
    Route::get('/soci', [SocioController::class, 'index'])->name('soci.index');
    Route::get('/soci/crea', [SocioController::class, 'create'])->name('soci.create');
    Route::post('/soci', [SocioController::class, 'store'])->name('soci.store');

    Route::get('/soci/cestino', [SocioController::class, 'cestino'])->name('soci.cestino');
    Route::get('/soci/export', [SocioController::class, 'export'])->name('soci.export');
    Route::get('/soci/import', [SocioController::class, 'import'])->name('soci.import');
    Route::post('/soci/import', [SocioController::class, 'importStore'])->name('soci.import.store');
    Route::get('/soci/search', [SocioController::class, 'search'])->name('soci.search');

    // Preset filtri salvati (spec §11)
    Route::post('/soci/filtri', [SavedFilterController::class, 'store'])->name('soci.filtri.store');
    Route::delete('/soci/filtri/{filtro}', [SavedFilterController::class, 'destroy'])->name('soci.filtri.destroy');

    // Cestino (miglioramento 11) — id esplicito perché il binding di default esclude i trashed
    Route::post('/soci/{id}/ripristina', [SocioController::class, 'restore'])->name('soci.restore');
    Route::delete('/soci/{id}/definitivo', [SocioController::class, 'forceDestroy'])->name('soci.force-destroy');

    // Transizioni di stato pre-iscrizione (spec §2)
    Route::post('/soci/{socio}/approva', [SocioStatoController::class, 'approva'])->name('soci.approva');
    Route::post('/soci/{socio}/conferma-pagamento', [SocioStatoController::class, 'confermaPagamento'])->name('soci.conferma-pagamento');
    Route::post('/soci/{socio}/rifiuta', [SocioStatoController::class, 'rifiuta'])->name('soci.rifiuta');

    // Documenti del socio (spec §10.2)
    Route::post('/soci/{socio}/documenti', [DocumentoSocioController::class, 'store'])->name('soci.documenti.store');
    Route::get('/soci/{socio}/documenti/{documento}/download', [DocumentoSocioController::class, 'download'])->name('soci.documenti.download');
    Route::delete('/soci/{socio}/documenti/{documento}', [DocumentoSocioController::class, 'destroy'])->name('soci.documenti.destroy');

    Route::get('/soci/{socio}/foto', [FotoSocioController::class, 'show'])->name('soci.foto.show');
    Route::post('/soci/{socio}/foto', [FotoSocioController::class, 'store'])->middleware('throttle:20,1')->name('soci.foto.store');
    Route::delete('/soci/{socio}/foto', [FotoSocioController::class, 'destroy'])->name('soci.foto.destroy');
    Route::get('/soci/{socio}/dati-personali', [DatiPersonaliController::class, 'esportaPerSocio'])
        ->middleware('throttle:10,1')
        ->name('soci.dati-personali');
    Route::get('/soci/{socio}', [SocioController::class, 'show'])->name('soci.show');
    Route::get('/soci/{socio}/modifica', [SocioController::class, 'edit'])->name('soci.edit');
    Route::put('/soci/{socio}', [SocioController::class, 'update'])->name('soci.update');
    Route::delete('/soci/{socio}', [SocioController::class, 'destroy'])->name('soci.destroy');

    // ── Configurazioni (solo admin, enforced dalle Policy) ──────────────
    Route::resource('tipi-socio', TipoSocioController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['tipi-socio' => 'tipoSocio']);

    Route::resource('categorie-socio', CategoriaSocioController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['categorie-socio' => 'categoriaSocio']);

    Route::post('/config-campi/preset', [CampoPersonalizzatoController::class, 'preset'])->name('config-campi.preset');
    Route::resource('config-campi', CampoPersonalizzatoController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['config-campi' => 'campo']);

    Route::resource('config-tags', TagController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['config-tags' => 'tag']);

    Route::resource('config-gruppi', GruppoDinamicoController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['config-gruppi' => 'gruppo']);
});
