<?php

namespace App\Http\Middleware;

use App\Services\Installer\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guardia dell'installer:
 * - app NON installata → tutte le rotte web reindirizzano a /install
 *   (il .env mancante viene creato da .env.example e l'APP_KEY generata al
 *   volo, così il wizard funziona anche senza accesso al terminale);
 * - app installata → /install* non è più raggiungibile.
 *
 * Registrato in PREPEND al gruppo web: deve agire prima di EncryptCookies.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('installer.enabled')) {
            return $next($request);
        }

        if (app()->environment('testing') && ! config('installer.enforce_in_tests')) {
            return $next($request);
        }

        $installed = file_exists(config('installer.lock_file'));

        if ($installed) {
            if ($request->is('install', 'install/*')) {
                return redirect('/login');
            }

            return $next($request);
        }

        // Non installata: crea il .env se manca e garantisce l'APP_KEY prima
        // che EncryptCookies la richieda (primo avvio senza terminale)
        $installer = app(InstallerService::class);
        if ($installer->preparaEnv()) {
            // .env appena creato: questa richiesta è partita senza (sessione,
            // cache e database con i valori predefiniti del framework), si
            // ricomincia con la configurazione giusta
            return redirect($request->fullUrl());
        }

        if ($request->is('up')) {
            return $next($request);
        }
        $rottaInstaller = $request->is('install', 'install/*');
        $token = $request->cookies->get((string) config('installer.in_progress_cookie'));
        $token = is_string($token) ? $token : null;

        if ($installer->adminExists()) {
            // Il super admin è l'ultimo step del wizard, che scrive il lock
            // nella stessa richiesta: qui si arriva solo con un'installazione
            // manuale via CLI, oppure con il lock perso in un deploy. Si
            // scrive il lock e si chiude il wizard, per qualunque browser
            $installer->markInstalled();

            return $rottaInstaller ? redirect('/login') : $next($request);
        }

        if ($rottaInstaller) {
            // Schema installato da un wizard in corso (marcatore presente) ma
            // super admin non ancora creato: gli step successivi restano al
            // browser che ha il token, altrimenti chiunque potrebbe creare
            // l'amministratore o rifare il database nel frattempo
            if (! $request->is('install') && $installer->wizardRivendicato() && $installer->databaseReady() && ! $installer->wizardInCorso($token)) {
                abort(403, 'Installazione in corso da un altro browser: completala da lì oppure, se è stata interrotta, elimina storage/app/installer-in-corso e ricomincia.');
            }

            return $next($request);
        }

        return redirect('/install');
    }
}
