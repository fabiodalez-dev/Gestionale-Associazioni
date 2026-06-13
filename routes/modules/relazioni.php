<?php

use App\Http\Controllers\Relazioni\AmbasciatoriController;
use App\Http\Controllers\Relazioni\ContattoEsternoController;
use App\Http\Controllers\Relazioni\GalleriaEventoController;
use App\Http\Controllers\Relazioni\SponsorController;
use App\Http\Controllers\Relazioni\StatisticheAnnualiController;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use App\Support\Relazioni\RelazioniPublicComposer;
use App\Support\Relazioni\RelazioniReferral;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: relazioni (sezione E del piano "Organizzazione 3", f.21–f.25)
|--------------------------------------------------------------------------
|
| f.21 Mini-CRM contatti esterni (tipi/tag/ricerca).
| f.22 Sponsor con livelli/periodo/logo + sezione "I nostri sostenitori"
|      sulla home pubblica del mini-sito (solo visibili e nel periodo).
| f.23 Referral "porta un amico": codice sul socio (generato in automatico
|      dal model), link in area soci/account, salvataggio sulla richiesta
|      di iscrizione (senza romperne il flusso), classifica ambasciatori
|      e contatore nella scheda socio.
| f.24 Galleria foto eventi (medialibrary 'galleria') con tab admin e
|      griglia sulla pagina pubblica dell'evento se galleria_pubblica.
| f.25 "Anno in cifre": Statistiche annuali con confronto anno precedente,
|      grafici Chart.js ed export PDF per l'assemblea.
|
| I view composer e il listener referral sono registrati qui (caricati al
| boot col file di rotte): non tocchiamo i provider/file di altri moduli.
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    // ---- f.21 Mini-CRM contatti esterni ----
    Route::get('/contatti-esterni', [ContattoEsternoController::class, 'index'])
        ->name('contatti-esterni.index');
    Route::post('/contatti-esterni', [ContattoEsternoController::class, 'store'])
        ->name('contatti-esterni.store');
    Route::put('/contatti-esterni/{contattiEsterni}', [ContattoEsternoController::class, 'update'])
        ->name('contatti-esterni.update');
    Route::delete('/contatti-esterni/{contattiEsterni}', [ContattoEsternoController::class, 'destroy'])
        ->name('contatti-esterni.destroy');

    // ---- f.22 Sponsor & partner ----
    Route::get('/sponsor', [SponsorController::class, 'index'])->name('sponsor.index');
    Route::post('/sponsor', [SponsorController::class, 'store'])->name('sponsor.store');
    Route::put('/sponsor/{sponsor}', [SponsorController::class, 'update'])->name('sponsor.update');
    Route::delete('/sponsor/{sponsor}', [SponsorController::class, 'destroy'])->name('sponsor.destroy');

    // ---- f.23 Classifica ambasciatori (porta un amico) ----
    Route::get('/ambasciatori', [AmbasciatoriController::class, 'index'])->name('ambasciatori.index');

    // ---- f.24 Galleria foto eventi (tab admin) ----
    Route::get('/eventi/{evento}/galleria', [GalleriaEventoController::class, 'index'])
        ->name('eventi.galleria.index');
    Route::post('/eventi/{evento}/galleria', [GalleriaEventoController::class, 'store'])
        ->name('eventi.galleria.store');
    // Serve dal disco privato nel tab admin (eventi.view); ?conv=thumb per la miniatura.
    Route::get('/eventi/{evento}/galleria/{media}/file', [GalleriaEventoController::class, 'servi'])
        ->name('eventi.galleria.file');
    Route::delete('/eventi/{evento}/galleria/{media}', [GalleriaEventoController::class, 'destroy'])
        ->name('eventi.galleria.destroy');
    Route::post('/eventi/{evento}/galleria/visibilita', [GalleriaEventoController::class, 'toggleVisibilita'])
        ->name('eventi.galleria.visibilita');

    // ---- f.25 Statistiche annuali ("Anno in cifre") ----
    Route::get('/statistiche-annuali', [StatisticheAnnualiController::class, 'index'])
        ->name('statistiche-annuali.index');
    Route::get('/statistiche-annuali/pdf', [StatisticheAnnualiController::class, 'pdf'])
        ->name('statistiche-annuali.pdf');
});

/*
 * f.24 — Serve PUBBLICO delle foto galleria dal disco privato. Vive sotto il
 * prefix a/{associazione:slug} con RisolviTenantPubblico (stesso gruppo di
 * pubblico.eventi.show): nessuna auth, ma il controller consente il download
 * SOLO se l'evento è pubblicato, del tenant e con galleria_pubblica attiva.
 * Nome rotta: pubblico.eventi.galleria.file. ?conv=thumb per la miniatura.
 */
Route::prefix('a/{associazione:slug}')
    ->name('pubblico.')
    ->middleware(RisolviTenantPubblico::class)
    ->group(function (): void {
        Route::get('/eventi/{evento}/galleria/{media}/file', [GalleriaEventoController::class, 'serviPubblico'])
            ->name('eventi.galleria.file');
    });

/*
 * Hook trasversali (registrati al boot, nessun file di altri moduli toccato):
 * - composer per "I nostri sostenitori" sulla home pubblica (f.22);
 * - composer per la griglia foto pubblica sulla pagina evento (f.24);
 * - composer per il link referral in area soci/account (f.23);
 * - listener che salva il referral sulla richiesta di iscrizione (f.23).
 */
RelazioniPublicComposer::register();
RelazioniReferral::register();
