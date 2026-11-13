<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Installer\AdminRequest;
use App\Http\Requests\Installer\DatabaseRequest;
use App\Http\Requests\Installer\EmailRequest;
use App\Rules\HostnameOIp;
use App\Services\Installer\InstallerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function __construct(private readonly InstallerService $installer)
    {
        // Difesa in profondità rispetto a EnsureInstalled: con l'installer
        // disattivato il wizard non deve esistere, qualunque sia l'ordine
        // dei middleware
        abort_unless(config('installer.enabled'), 404);
    }

    /**
     * Gli step che scrivono (.env, schema, super admin) non girano MAI su
     * un'applicazione già installata, anche se il middleware venisse
     * aggirato: il lock è autorevole.
     */
    private function rifiutaSeInstallata(): void
    {
        abort_if(file_exists((string) config('installer.lock_file')), 404);
    }

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

    /** Test di connessione AJAX dal form database */
    public function databaseTest(DatabaseRequest $request): JsonResponse
    {
        $esito = $this->installer->testDatabase(
            $request->validated(),
            $request->boolean('crea_database')
        );

        return response()->json($esito);
    }

    public function databaseStore(DatabaseRequest $request): RedirectResponse
    {
        $this->rifiutaSeInstallata();

        $cfg = $request->validated();

        $esito = $this->installer->testDatabase($cfg, $request->boolean('crea_database'));

        if (! $esito['ok']) {
            return back()
                ->withInput()
                ->withErrors(['database' => 'Connessione fallita: '.$esito['error']]);
        }

        // Un database con un'installazione completa non si tocca MAI dal
        // wizard (raggiungibile senza login): per reinstallare si passa
        // dalla CLI, che fa prima il backup
        if ($esito['installata']) {
            return back()
                ->withInput()
                ->withErrors(['database' => "Il database \"{$cfg['database']}\" contiene già un'installazione di Gestione Associazioni con un super amministratore. Per reinstallare da zero esegui prima \"php artisan app:disinstalla\" (fa il backup e svuota il database), oppure indica un altro database."]);
        }

        // Protezione database non vuoto (come l'installer di riferimento):
        // lo schema sovrascrive le tabelle con lo stesso nome, quindi si
        // procede solo con conferma esplicita, elencando cosa verrà perso
        if ($esito['tables'] > 0 && ! $request->boolean('consenti_non_vuoto')) {
            $daSovrascrivere = $this->installer->tabelleDaSovrascrivere($esito['tabelle']);
            $dettaglio = $daSovrascrivere === []
                ? 'Nessuna di queste è una tabella dell\'applicazione: resteranno intatte.'
                : sprintf('Le tabelle dell\'applicazione già presenti verranno cancellate e ricreate vuote (%d): %s. Le altre restano intatte.', count($daSovrascrivere), implode(', ', $daSovrascrivere));

            return back()
                ->withInput()
                ->withErrors(['database' => "Il database \"{$cfg['database']}\" contiene già {$esito['tables']} tabelle. {$dettaglio} Per procedere spunta \"Sovrascrivi le tabelle già presenti\"."]);
        }

        // Prima lo schema, poi il .env: se l'import fallisce la configurazione
        // precedente resta com'era e l'utente può correggere e riprovare
        try {
            $risultato = $this->installer->installaSchema($cfg);
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->withErrors(['database' => 'Installazione dello schema fallita: '.$e->getMessage()]);
        }

        $valori = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $cfg['host'],
            'DB_PORT' => (string) $cfg['port'],
            'DB_DATABASE' => $cfg['database'],
            'DB_USERNAME' => $cfg['username'],
            'DB_PASSWORD' => (string) ($cfg['password'] ?? ''),
        ];

        // root() include l'eventuale sottocartella (es. /public o
        // /gestionale/public): gli URL generati devono partire da lì. Deriva
        // dall'header Host della richiesta, quindi si scrive nel .env solo se
        // è un URL http(s) con un host plausibile (stessa regola della pagina
        // Impostazioni di sistema); altrimenti resta l'APP_URL già presente
        $appUrl = rtrim($request->root(), '/');
        if ($this->appUrlValido($appUrl)) {
            $valori['APP_URL'] = $appUrl;
            if (parse_url($appUrl, PHP_URL_SCHEME) === 'https') {
                // Il sito è servito in https: il cookie di sessione non deve
                // mai viaggiare in chiaro
                $valori['SESSION_SECURE_COOKIE'] = 'true';
            }
        } else {
            Log::warning('Installer: APP_URL non scritto perché l\'URL della richiesta non è valido', ['url' => $appUrl]);
        }

        $this->installer->writeEnv($valori);

        // Token in cookie HttpOnly (non cifrato: il middleware EnsureInstalled
        // gira prima di EncryptCookies), valido quanto il marcatore
        $token = $this->installer->segnaWizardInCorso();
        $cookie = cookie(
            name: (string) config('installer.in_progress_cookie'),
            value: $token,
            minutes: (int) config('installer.in_progress_ttl_minutes', 120),
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        );

        return redirect()->route('installer.admin')
            ->withCookie($cookie)
            ->with('success', "Database configurato: schema installato ({$risultato['tabelle']} tabelle).");
    }

    /**
     * URL http(s) assoluto (max 255, come il campo APP_URL delle Impostazioni
     * di sistema) con un host che sia un nome host o un indirizzo IP.
     */
    private function appUrlValido(string $url): bool
    {
        if (Validator::make(['url' => $url], ['url' => ['required', 'string', 'max:255', 'url:http,https']])->fails()) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return HostnameOIp::valido(trim($host, '[]'));
    }

    /** Step 3 — super amministratore e prima associazione */
    public function admin(): View|RedirectResponse
    {
        if (! $this->installer->databaseReady()) {
            return redirect()->route('installer.database')
                ->withErrors(['database' => 'Configura prima il database.']);
        }

        if ($this->installer->adminExists()) {
            return redirect()->route('installer.email');
        }

        return view('installer.admin', ['step' => 3]);
    }

    public function adminStore(AdminRequest $request): RedirectResponse
    {
        $this->rifiutaSeInstallata();

        if (! $this->installer->databaseReady()) {
            return redirect()->route('installer.database');
        }

        if (! $this->installer->adminExists()) {
            $this->installer->createAdmin($request->validated());

            if ($request->boolean('dati_demo')) {
                $this->installer->seedDemo();
            }
        }

        return redirect()->route('installer.email');
    }

    /** Step 4 — email di piattaforma (facoltativo) */
    public function email(): View|RedirectResponse
    {
        if (! $this->installer->adminExists()) {
            return redirect()->route('installer.admin');
        }

        return view('installer.email', ['step' => 4]);
    }

    public function emailStore(EmailRequest $request): RedirectResponse
    {
        $this->rifiutaSeInstallata();

        $dati = $request->validated();

        if (($dati['driver'] ?? 'log') === 'smtp') {
            $this->installer->writeEnv(array_filter([
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => $dati['smtp_host'],
                'MAIL_PORT' => (string) $dati['smtp_port'],
                'MAIL_USERNAME' => $dati['smtp_username'] ?? '',
                'MAIL_PASSWORD' => $dati['smtp_password'] ?? '',
                // 'tls' (STARTTLS) usa scheme 'smtp': Symfony accetta solo smtp|smtps
                'MAIL_SCHEME' => ($dati['smtp_encryption'] ?? null) === 'smtps' ? 'smtps' : 'smtp',
                'MAIL_FROM_ADDRESS' => $dati['from_address'],
                'MAIL_FROM_NAME' => $dati['from_name'],
            ], fn ($v) => $v !== null));
        } elseif (filled($dati['from_address'] ?? null)) {
            $this->installer->writeEnv([
                'MAIL_FROM_ADDRESS' => $dati['from_address'],
                'MAIL_FROM_NAME' => $dati['from_name'] ?? config('app.name'),
            ]);
        }

        return redirect()->route('installer.done');
    }

    /** Step 5 — riepilogo e lock */
    public function done(): View|RedirectResponse
    {
        if (! $this->installer->adminExists()) {
            return redirect()->route('installer.admin');
        }

        $this->installer->markInstalled();

        return view('installer.done', [
            'step' => 5,
            'storageCollegato' => $this->installer->collegaStorage(),
        ]);
    }
}
