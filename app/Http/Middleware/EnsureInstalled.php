<?php

namespace App\Http\Middleware;

use App\Services\Installer\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guardia dell'installer:
 * - app NON installata → tutte le rotte web reindirizzano a /install
 *   (e l'APP_KEY mancante viene generata al volo, così il wizard funziona
 *   anche senza accesso al terminale);
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

        // Non installata: garantisce l'APP_KEY prima che EncryptCookies la richieda
        if (blank(config('app.key')) && is_writable(config('installer.env_file'))) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        if ($request->is('up')) {
            return $next($request);
        }

        $installer = app(InstallerService::class);
        $rottaInstaller = $request->is('install', 'install/*');

        if ($installer->adminExists()) {
            // Il wizard stesso ha appena creato il super admin e sta
            // completando gli ultimi step dallo stesso browser (token nel
            // cookie): restano aperti solo email e fine, non più il database
            $token = $request->cookies->get((string) config('installer.in_progress_cookie'));
            if ($rottaInstaller && $installer->wizardInCorso(is_string($token) ? $token : null)) {
                return $request->is('install/database*')
                    ? redirect()->route('installer.email')
                    : $next($request);
            }

            // Lock assente ma database già installato (installazione manuale
            // via CLI, oppure lock perso in un deploy): scrive il lock e
            // chiude il wizard, che altrimenti resterebbe aperto senza login
            $installer->markInstalled();

            return $rottaInstaller ? redirect('/login') : $next($request);
        }

        if ($rottaInstaller) {
            return $next($request);
        }

        return redirect('/install');
    }
}
