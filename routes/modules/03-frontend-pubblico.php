<?php

use App\Http\Controllers\FrontendPubblico\Account\DashboardAccountController;
use App\Http\Controllers\FrontendPubblico\Account\DiventaSocioController;
use App\Http\Controllers\FrontendPubblico\Account\PreferenzeAccountController;
use App\Http\Controllers\FrontendPubblico\Account\PrenotazioniAccountController;
use App\Http\Controllers\FrontendPubblico\Account\PrivacyAccountController;
use App\Http\Controllers\FrontendPubblico\Account\ProfiloAccountController;
use App\Http\Controllers\FrontendPubblico\Account\TesseraQuoteController;
use App\Http\Controllers\FrontendPubblico\CatalogoEventiController;
use App\Http\Controllers\FrontendPubblico\HomeController;
use App\Http\Controllers\FrontendPubblico\IscrizioneController;
use App\Http\Controllers\FrontendPubblico\LoginController;
use App\Http\Controllers\FrontendPubblico\PagamentoPrenotazioneController;
use App\Http\Controllers\FrontendPubblico\PasswordResetController;
use App\Http\Controllers\FrontendPubblico\PrenotazioneController;
use App\Http\Controllers\FrontendPubblico\RegistrazioneController;
use App\Http\Controllers\FrontendPubblico\VerificaEmailController;
use App\Http\Controllers\Privacy\DatiPersonaliController;
use App\Http\Middleware\FrontendPubblico\AutenticaAccount;
use App\Http\Middleware\FrontendPubblico\EnsureAccountValido;
use App\Http\Middleware\FrontendPubblico\EnsureAccountVerificato;
use App\Http\Middleware\FrontendPubblico\RisolviTenantPubblico;
use Illuminate\Support\Facades\Route;
use Spatie\Honeypot\ProtectAgainstSpam;

/*
|--------------------------------------------------------------------------
| Modulo: frontend pubblico prenotazioni (/a/{slug})
|--------------------------------------------------------------------------
|
| Mini-sito eventi brandizzato per-associazione. Tutte le rotte vivono
| sotto il prefix a/{associazione:slug} con RisolviTenantPubblico:
| - 404 uniforme se lo slug non esiste o il frontend è disattivato;
| - binding app('tenant.pubblico') per il global scope multi-tenant;
| - logout della guard 'account' su navigazione cross-tenant.
|
| Gli altri moduli (auth pubblica, catalogo eventi, prenotazioni,
| area riservata account) aggiungono le proprie rotte DENTRO questo
| gruppo, con i middleware AutenticaAccount / EnsureAccountValido /
| EnsureAccountVerificato per le rotte autenticate.
|
*/

Route::prefix('a/{associazione:slug}')
    ->name('pubblico.')
    ->middleware(RisolviTenantPubblico::class)
    ->group(function (): void {

        /*
        |------------------------------------------------------------------
        | Catalogo pubblico (home + eventi pubblicati)
        |------------------------------------------------------------------
        */

        Route::get('/', [HomeController::class, 'show'])->name('home.show');

        Route::get('/eventi', [CatalogoEventiController::class, 'index'])->name('eventi.index');
        Route::get('/eventi/{evento}', [CatalogoEventiController::class, 'show'])->name('eventi.show');

        /*
        |------------------------------------------------------------------
        | Registrazione (honeypot spatie + throttle 5/m)
        |------------------------------------------------------------------
        */

        Route::get('/registrati', [RegistrazioneController::class, 'create'])->name('registrazione');
        Route::post('/registrati', [RegistrazioneController::class, 'store'])
            ->middleware([ProtectAgainstSpam::class, 'throttle:5,1'])
            ->name('registrazione.store');

        /*
        |------------------------------------------------------------------
        | Verifica email (link firmato; avviso e reinvio autenticati)
        |------------------------------------------------------------------
        */

        Route::get('/verifica-email', [VerificaEmailController::class, 'avviso'])
            ->middleware([AutenticaAccount::class.':account', EnsureAccountValido::class])
            ->name('verifica-email.avviso');

        Route::get('/verifica-email/{id}/{hash}', [VerificaEmailController::class, 'verifica'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verifica-email.verifica');

        Route::post('/verifica-email/reinvia', [VerificaEmailController::class, 'reinvia'])
            ->middleware([AutenticaAccount::class.':account', EnsureAccountValido::class, 'throttle:6,1'])
            ->name('verifica-email.reinvia');

        /*
        |------------------------------------------------------------------
        | Login / logout (guard 'account', lookup tenant-scoped)
        |------------------------------------------------------------------
        */

        Route::get('/login', [LoginController::class, 'show'])->name('login');

        // Rate limit per IP; il throttling per email+IP è nel Form Request
        Route::post('/login', [LoginController::class, 'login'])
            ->middleware('throttle:30,1')
            ->name('login.attempt');

        Route::post('/logout', [LoginController::class, 'logout'])
            ->middleware(AutenticaAccount::class.':account')
            ->name('logout');

        /*
        |------------------------------------------------------------------
        | Password reset (broker 'accounts', risposta neutra)
        |------------------------------------------------------------------
        */

        Route::get('/password/reset', [PasswordResetController::class, 'richiesta'])->name('password.richiesta');

        Route::post('/password/email', [PasswordResetController::class, 'invia'])
            ->middleware('throttle:5,1')
            ->name('password.email');

        Route::get('/password/reset/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');

        Route::post('/password/reset', [PasswordResetController::class, 'aggiorna'])
            ->middleware('throttle:10,1')
            ->name('password.aggiorna');

        /*
        |------------------------------------------------------------------
        | Prenotazioni (guard account + valido + verificato)
        |------------------------------------------------------------------
        |
        | {evento} e {prenotazione} sono parametri stringa (NIENTE binding
        | implicito: scatterebbe prima del binding tenant.pubblico) risolti
        | dal controller sotto tenant scope → 404 uniforme cross-tenant.
        |
        */
        /*
        |------------------------------------------------------------------
        | Iscrizione socio (form personalizzabile per tenant) — PUBBLICA
        |------------------------------------------------------------------
        |
        | Aperta anche agli OSPITI senza account: per loro il form chiede
        | anche nome/cognome/email + consenso privacy e la richiesta nasce
        | senza account collegato. Con account loggato l'anagrafica arriva
        | dall'account e la richiesta resta collegata.
        | GET: iscrizioni chiuse → pagina dedicata; già socio → redirect
        | tessera; richiesta in_attesa (solo con account) → pagina stato.
        | POST: honeypot spatie + throttle 5/m, rules() dinamiche.
        |
        */
        Route::get('/iscriviti', [IscrizioneController::class, 'show'])
            ->name('iscriviti');

        Route::post('/iscriviti', [IscrizioneController::class, 'store'])
            ->middleware([ProtectAgainstSpam::class, 'throttle:5,1'])
            ->name('iscriviti.store');

        Route::middleware([
            AutenticaAccount::class.':account',
            EnsureAccountValido::class,
            EnsureAccountVerificato::class,
        ])->group(function (): void {

            Route::post('/eventi/{evento}/prenota', [PrenotazioneController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('eventi.prenota');

            Route::get('/prenotazioni/{prenotazione}/esito', [PrenotazioneController::class, 'esito'])
                ->name('prenotazioni.esito');

            // Pagamento prenotazione: scelta gateway (countdown scadenza),
            // checkout Stripe/PayPal (l'esito success/cancel torna su
            // prenotazioni.esito con ?esito=, lo stato live fa fede)
            Route::get('/prenotazioni/{prenotazione}/paga', [PagamentoPrenotazioneController::class, 'paga'])
                ->name('prenotazioni.paga');

            Route::post('/prenotazioni/{prenotazione}/checkout/{gateway}', [PagamentoPrenotazioneController::class, 'checkout'])
                ->whereIn('gateway', ['stripe', 'paypal'])
                ->middleware('throttle:10,1')
                ->name('prenotazioni.checkout');
        });

        /*
        |------------------------------------------------------------------
        | Area riservata account (/a/{slug}/account/*)
        |------------------------------------------------------------------
        |
        | Guard 'account' + valido + verificato. {prenotazione} è un
        | parametro stringa risolto nei controller sotto tenant scope
        | (PrenotazionePolicy: l'account vede solo le proprie).
        |
        */
        Route::prefix('account')
            ->name('account.')
            ->middleware([
                AutenticaAccount::class.':account',
                EnsureAccountValido::class,
                EnsureAccountVerificato::class,
            ])->group(function (): void {

                Route::get('/', [DashboardAccountController::class, 'show'])->name('dashboard');

                // Prenotazioni: elenco con filtri, dettaglio con QR inline,
                // biglietto PDF (solo confermate), annullo, storico passato
                Route::get('/prenotazioni', [PrenotazioniAccountController::class, 'index'])
                    ->name('prenotazioni.index');

                Route::get('/storico', [PrenotazioniAccountController::class, 'storico'])
                    ->name('storico');

                Route::get('/prenotazioni/{prenotazione}', [PrenotazioniAccountController::class, 'show'])
                    ->name('prenotazioni.show');

                Route::get('/prenotazioni/{prenotazione}/biglietto.pdf', [PrenotazioniAccountController::class, 'biglietto'])
                    ->name('prenotazioni.biglietto');

                Route::post('/prenotazioni/{prenotazione}/annulla', [PrenotazioniAccountController::class, 'annulla'])
                    ->middleware('throttle:10,1')
                    ->name('prenotazioni.annulla');

                // Profilo: anagrafica, cambio password (con password corrente),
                // cambio email (azzera la verifica e reinvia il link)
                Route::get('/profilo', [ProfiloAccountController::class, 'show'])->name('profilo');
                Route::patch('/profilo', [ProfiloAccountController::class, 'aggiorna'])->name('profilo.aggiorna');
                Route::patch('/profilo/password', [ProfiloAccountController::class, 'password'])
                    ->middleware('throttle:10,1')
                    ->name('profilo.password');
                Route::patch('/profilo/email', [ProfiloAccountController::class, 'email'])
                    ->middleware('throttle:6,1')
                    ->name('profilo.email');

                // Preferenze json (newsletter / promemoria eventi / marketing)
                Route::get('/preferenze', [PreferenzeAccountController::class, 'show'])->name('preferenze');
                Route::patch('/preferenze', [PreferenzeAccountController::class, 'aggiorna'])->name('preferenze.aggiorna');

                // Privacy GDPR: export JSON + cancellazione (anonimizzazione)
                Route::get('/privacy', [PrivacyAccountController::class, 'show'])->name('privacy');
                Route::get('/privacy/export', [PrivacyAccountController::class, 'export'])->name('privacy.export');
                Route::post('/privacy/archivio', [DatiPersonaliController::class, 'richiediAccount'])
                    ->middleware('throttle:5,1')
                    ->name('privacy.archivio');
                Route::get('/privacy/archivio/{file}', [DatiPersonaliController::class, 'scaricaAccount'])
                    ->middleware('signed:relative')
                    ->name('privacy.archivio.scarica');
                Route::post('/privacy/cancella', [PrivacyAccountController::class, 'cancella'])
                    ->middleware('throttle:5,1')
                    ->name('privacy.cancella');

                // Diventa socio: richiesta con motivazione → notifica admin
                Route::get('/diventa-socio', [DiventaSocioController::class, 'show'])->name('diventa-socio');
                Route::post('/diventa-socio', [DiventaSocioController::class, 'store'])
                    ->middleware('throttle:5,1')
                    ->name('diventa-socio.store');

                // Tessera + quote: SOLO se l'account è collegato a un socio
                Route::get('/tessera', [TesseraQuoteController::class, 'show'])->name('tessera');
            });
    });
