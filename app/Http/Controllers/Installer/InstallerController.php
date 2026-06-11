<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Installer\AdminRequest;
use App\Http\Requests\Installer\DatabaseRequest;
use App\Services\Installer\InstallerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function __construct(private readonly InstallerService $installer) {}

    /** Step 1 — requisiti di sistema */
    public function requirements(): View
    {
        return view('installer.requirements', [
            'req' => $this->installer->requirements(),
            'step' => 1,
        ]);
    }

    /** Step 2 — configurazione database */
    public function database(): View
    {
        return view('installer.database', [
            'step' => 2,
            'dbReady' => $this->installer->databaseReady(),
        ]);
    }

    public function databaseStore(DatabaseRequest $request): RedirectResponse
    {
        $cfg = $request->validated();

        $esito = $this->installer->testDatabase($cfg, $request->boolean('crea_database'));

        if (! $esito['ok']) {
            return back()
                ->withInput()
                ->withErrors(['database' => 'Connessione fallita: '.$esito['error']]);
        }

        $this->installer->writeEnv([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $cfg['host'],
            'DB_PORT' => (string) $cfg['port'],
            'DB_DATABASE' => $cfg['database'],
            'DB_USERNAME' => $cfg['username'],
            'DB_PASSWORD' => (string) ($cfg['password'] ?? ''),
            'APP_URL' => $request->getSchemeAndHttpHost(),
        ]);

        $this->installer->runMigrations($cfg);

        return redirect()->route('installer.admin')
            ->with('success', 'Database configurato e migrato correttamente.');
    }

    /** Step 3 — super amministratore e prima associazione */
    public function admin(): View|RedirectResponse
    {
        if (! $this->installer->databaseReady()) {
            return redirect()->route('installer.database')
                ->withErrors(['database' => 'Configura prima il database.']);
        }

        if ($this->installer->adminExists()) {
            return redirect()->route('installer.done');
        }

        return view('installer.admin', ['step' => 3]);
    }

    public function adminStore(AdminRequest $request): RedirectResponse
    {
        if (! $this->installer->databaseReady()) {
            return redirect()->route('installer.database');
        }

        if (! $this->installer->adminExists()) {
            $this->installer->createAdmin($request->validated());
        }

        return redirect()->route('installer.done');
    }

    /** Step 4 — riepilogo e lock */
    public function done(): View|RedirectResponse
    {
        if (! $this->installer->adminExists()) {
            return redirect()->route('installer.admin');
        }

        $this->installer->markInstalled();

        return view('installer.done', ['step' => 4]);
    }
}
