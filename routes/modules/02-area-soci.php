<?php

use App\Http\Controllers\AreaSoci\AssembleeController;
use App\Http\Controllers\AreaSoci\DashboardController;
use App\Http\Controllers\AreaSoci\EmailOptOutController;
use App\Http\Controllers\AreaSoci\LoginController;
use App\Http\Controllers\AreaSoci\PasswordResetController;
use App\Http\Controllers\AreaSoci\ProfiloController;
use App\Http\Controllers\AreaSoci\QuoteController;
use App\Http\Controllers\AreaSoci\SetPasswordController;
use App\Http\Controllers\AreaSoci\TessereController;
use App\Http\Controllers\Privacy\DatiPersonaliController;
use App\Http\Middleware\AreaSoci\AutenticaSocio;
use App\Http\Middleware\AreaSoci\EnsureSocioValido;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: area-soci (portale member-facing)
|--------------------------------------------------------------------------
|
| Guard dedicata 'socio' (tabella soci), separata dalla sessione admin:
| il logout del socio non tocca un'eventuale sessione amministrativa.
| Le rotte pubbliche (login, set password, opt-out email) sono fuori
| dal middleware di autenticazione; le rotte autenticate usano
| AutenticaSocio (redirect alla login del portale) + EnsureSocioValido
| (logout forzato se il socio è stato cancellato) + 'tenant'.
|
*/

Route::prefix('area-soci')->name('area-soci.')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Rotte pubbliche (senza autenticazione)
    |----------------------------------------------------------------------
    */

    Route::get('/login', [LoginController::class, 'show'])->name('login');

    // Rate limit aggiuntivo per IP (il vero throttling per email+IP è nel Form Request)
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:30,1')
        ->name('login.attempt');

    // Richiesta link "imposta password" (primo accesso o reset) — risposta neutra
    Route::post('/password/email', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    // Set password con token monouso (TTL 1 ora)
    Route::get('/set-password/{token}', [SetPasswordController::class, 'show'])
        ->where('token', '[A-Fa-f0-9]{64}')
        ->name('set-password');
    Route::post('/set-password/{token}', [SetPasswordController::class, 'store'])
        ->where('token', '[A-Fa-f0-9]{64}')
        ->middleware('throttle:10,1')
        ->name('set-password.store');

    // Opt-out email via token (link nel footer delle comunicazioni):
    // GET = pagina di conferma, POST (CSRF) = applica l'opt-out
    Route::get('/opt-out/{token}', [EmailOptOutController::class, 'show'])
        ->where('token', '[A-Fa-f0-9]{64}')
        ->name('optout');
    Route::post('/opt-out/{token}', [EmailOptOutController::class, 'store'])
        ->where('token', '[A-Fa-f0-9]{64}')
        ->middleware('throttle:10,1')
        ->name('optout.store');

    /*
    |----------------------------------------------------------------------
    | Rotte autenticate (guard socio + tenant)
    |----------------------------------------------------------------------
    */

    Route::middleware([AutenticaSocio::class.':socio', EnsureSocioValido::class, 'tenant'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/profilo', [ProfiloController::class, 'show'])->name('profilo');
        Route::patch('/profilo', [ProfiloController::class, 'update'])->name('profilo.update');

        Route::get('/quote', [QuoteController::class, 'index'])->name('quote');

        Route::get('/tessere', [TessereController::class, 'index'])->name('tessere');
        Route::get('/tessere/{tessera}/pdf', [TessereController::class, 'pdf'])->name('tessere.pdf');

        // Archivio dei dati personali (GDPR): richiesta (email con link) e download firmato
        Route::post('/dati-personali', [DatiPersonaliController::class, 'richiediSocio'])
            ->middleware('throttle:5,1')
            ->name('dati-personali.richiedi');
        Route::get('/dati-personali/{file}', [DatiPersonaliController::class, 'scaricaSocio'])
            ->middleware('signed:relative')
            ->name('dati-personali.scarica');

        // Assemblee dei soci e deleghe (issues #6 e #7)
        Route::get('/assemblee', [AssembleeController::class, 'index'])->name('assemblee');
        Route::post('/assemblee/{riunione}/delega', [AssembleeController::class, 'delega'])
            ->middleware('throttle:10,1')
            ->name('assemblee.delega');
        Route::delete('/assemblee/{riunione}/delega', [AssembleeController::class, 'revoca'])
            ->middleware('throttle:10,1')
            ->name('assemblee.revoca');
        // Assemblea in corso: presenza a distanza e voto online (issue #8)
        Route::get('/assemblee/{riunione}', [AssembleeController::class, 'show'])->name('assemblee.show');
        Route::post('/assemblee/{riunione}/presenza', [AssembleeController::class, 'presenza'])
            ->middleware('throttle:10,1')
            ->name('assemblee.presenza');
        Route::post('/assemblee/{riunione}/votazioni/{votazione}', [AssembleeController::class, 'vota'])
            ->middleware('throttle:30,1')
            ->name('assemblee.vota');
        // Elezioni: candidature e scheda (issue #9)
        Route::post('/elezioni/{elezione}/candidatura', [AssembleeController::class, 'candida'])
            ->middleware('throttle:10,1')
            ->name('elezioni.candida');
        Route::post('/elezioni/{elezione}/candidatura/accetta', [AssembleeController::class, 'accettaCandidatura'])
            ->name('elezioni.accetta');
        Route::delete('/elezioni/{elezione}/candidatura', [AssembleeController::class, 'ritiraCandidatura'])
            ->name('elezioni.ritira');
        Route::post('/elezioni/{elezione}/vota', [AssembleeController::class, 'votaElezione'])
            ->middleware('throttle:30,1')
            ->name('elezioni.vota');

        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    });
});
