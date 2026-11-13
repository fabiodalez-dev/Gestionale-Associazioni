<?php

namespace App\Http\Middleware\ApiV1;

use App\Services\Installer\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guardia installer per la REST API /api/v1 (gruppo `api`, senza sessione).
 *
 * EnsureInstalled vive nel gruppo web e, con l'app non installata,
 * reindirizza a /install: per un client API un redirect HTML non ha senso.
 * Qui le stesse condizioni producono un 503 JSON (`not_installed`).
 *
 * Come EnsureInstalled, se il file di lock manca ma il database è già
 * installato (installazione manuale via CLI o lock perso in un deploy) il
 * lock viene riscritto e la richiesta prosegue, così un client API non resta
 * bloccato finché qualcuno non apre il pannello web. Non lo fa mentre un
 * wizard è in corso: il completamento spetta al browser del wizard.
 */
class EnsureApiInstallata
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('installer.enabled')) {
            return $next($request);
        }

        if (app()->environment('testing') && ! config('installer.enforce_in_tests')) {
            return $next($request);
        }

        if (file_exists((string) config('installer.lock_file'))) {
            return $next($request);
        }

        $installer = app(InstallerService::class);

        if (! $installer->wizardRivendicato() && $installer->adminExists()) {
            $installer->markInstalled();

            return $next($request);
        }

        return response()->json([
            'success' => false,
            'error' => 'Applicazione non ancora installata: completa la procedura di installazione dal pannello web.',
            'code' => 'not_installed',
        ], 503);
    }
}
