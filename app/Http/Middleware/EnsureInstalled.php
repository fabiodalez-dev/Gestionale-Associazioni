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

        if ($request->is('install', 'install/*', 'up')) {
            return $next($request);
        }

        // Installazione manuale via CLI (migrate + admin creato a mano):
        // riconosce lo stato e scrive il lock senza passare dal wizard.
        $installer = app(InstallerService::class);
        if ($installer->adminExists()) {
            $installer->markInstalled();

            return $next($request);
        }

        return redirect('/install');
    }
}
