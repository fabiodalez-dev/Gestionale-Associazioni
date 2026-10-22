<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Utente;
use App\Services\Sistema\EnvEditor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Pagina "Impostazioni di sistema" (/impostazioni-sistema) — SOLO super_admin.
 * Modifica il file .env: applicazione, email SMTP di sistema, sicurezza accessi,
 * database, backup e aggiornamenti. Le sezioni sono descritte da sezioni().
 */
class ImpostazioniSistemaController extends Controller
{
    public function __construct(private readonly EnvEditor $env) {}

    public function index(Request $request, ?string $sezione = null): View
    {
        $this->soloSuperAdmin($request);

        $sezioni = $this->sezioni();
        $sezione = $sezione !== null && (isset($sezioni[$sezione]) || $sezione === 'sistema') ? $sezione : 'generale';

        return view('impostazioni-sistema.index', [
            'sezioni' => $sezioni,
            'attiva' => $sezione,
            'valori' => $this->env->read(),
            'scrivibile' => $this->env->isWritable(),
            'avvisi' => $this->avvisi(),
            'info' => $sezione === 'sistema' ? $this->infoSistema() : [],
        ]);
    }

    public function update(Request $request, string $sezione): RedirectResponse
    {
        $this->soloSuperAdmin($request);

        $sezioni = $this->sezioni();
        abort_unless(isset($sezioni[$sezione]), 404);
        $campi = $sezioni[$sezione]['campi'];

        $dati = $request->validate(
            collect($campi)->mapWithKeys(fn (array $c, string $k) => [$k => $c['rules']])->all(),
            [],
            collect($campi)->mapWithKeys(fn (array $c, string $k) => [$k => $c['label']])->all(),
        );

        $modifiche = $this->modificheDaInput($campi, $dati, $request);

        if ($sezione === 'database') {
            $errore = $this->verificaDatabase($modifiche);
            if ($errore !== null) {
                return back()->withInput($request->except('DB_PASSWORD'))->with('error', $errore.' La configurazione NON è stata salvata.');
            }
        }

        try {
            $this->env->write($modifiche);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput($request->except($this->campiSegreti($campi)))->with('error', $e->getMessage());
        }

        return redirect()
            ->route('impostazioni-sistema.index', ['sezione' => $sezione])
            ->with('success', $sezioni[$sezione]['titolo'].': impostazioni salvate.');
    }

    /**
     * Invia un'email di prova con i valori del form (anche non ancora salvati).
     */
    public function testEmail(Request $request): RedirectResponse
    {
        $this->soloSuperAdmin($request);

        $campi = $this->sezioni()['email']['campi'];
        $dati = $request->validate(
            collect($campi)->mapWithKeys(fn (array $c, string $k) => [$k => $c['rules']])->all() + ['test_destinatario' => ['required', 'email']],
        );
        $cfg = $this->modificheDaInput($campi, $dati, $request);

        try {
            Mail::build([
                'transport' => $cfg['MAIL_MAILER'],
                'scheme' => $cfg['MAIL_SCHEME'] !== '' ? $cfg['MAIL_SCHEME'] : null,
                'host' => $cfg['MAIL_HOST'],
                'port' => (int) $cfg['MAIL_PORT'],
                'username' => $cfg['MAIL_USERNAME'] !== '' ? $cfg['MAIL_USERNAME'] : null,
                'password' => $cfg['MAIL_PASSWORD'] !== '' ? $cfg['MAIL_PASSWORD'] : null,
                'timeout' => 15,
                'path' => '/usr/sbin/sendmail -bs -i',
                'channel' => config('logging.default'),
            ])->raw(
                "Questa è un'email di prova inviata dalle Impostazioni di sistema di ".config('app.name').".\n\nSe la ricevi, la configurazione SMTP funziona.",
                function ($message) use ($cfg, $dati): void {
                    $message->from($cfg['MAIL_FROM_ADDRESS'], $cfg['MAIL_FROM_NAME'])
                        ->to($dati['test_destinatario'])
                        ->subject('Email di prova — '.config('app.name'));
                },
            );
        } catch (Throwable $e) {
            report($e);

            return back()->withInput($request->except('MAIL_PASSWORD'))->with('error', 'Invio di prova fallito: '.$e->getMessage());
        }

        $nota = $cfg['MAIL_MAILER'] === 'log' ? ' Con il mailer "log" l\'email è stata scritta nel log, non inviata davvero.' : '';

        return back()->withInput($request->except('MAIL_PASSWORD'))->with('success', 'Email di prova inviata a '.$dati['test_destinatario'].'.'.$nota.' Ricordati di salvare la configurazione.');
    }

    /**
     * Verifica la connessione al database con i valori del form, senza salvarli.
     */
    public function testDatabase(Request $request): RedirectResponse
    {
        $this->soloSuperAdmin($request);

        $campi = $this->sezioni()['database']['campi'];
        $dati = $request->validate(collect($campi)->mapWithKeys(fn (array $c, string $k) => [$k => $c['rules']])->all());
        $errore = $this->verificaDatabase($this->modificheDaInput($campi, $dati, $request));

        return back()->withInput($request->except('DB_PASSWORD'))
            ->with($errore === null ? 'success' : 'error', $errore ?? 'Connessione al database riuscita.');
    }

    /**
     * Definizione delle sezioni modificabili: chiave .env => campo del form.
     *
     * @return array<string, array{titolo: string, icona: string, descrizione: string, campi: array<string, array<string, mixed>>}>
     */
    private function sezioni(): array
    {
        $bool = ['nullable', 'boolean'];
        $int = fn (int $min, int $max) => ['required', 'integer', 'min:'.$min, 'max:'.$max];

        return [
            'generale' => [
                'titolo' => 'Generale',
                'icona' => 'bi-sliders',
                'descrizione' => 'Nome, indirizzo pubblico e modalità di esecuzione dell\'applicazione.',
                'campi' => [
                    'APP_NAME' => ['label' => 'Nome applicazione', 'tipo' => 'text', 'rules' => ['required', 'string', 'max:100'], 'default' => 'Gestione Associazioni'],
                    'APP_URL' => ['label' => 'URL pubblico', 'tipo' => 'url', 'rules' => ['required', 'url:http,https', 'max:255'], 'help' => 'Senza slash finale. Si usa nei link delle email (recupero password, inviti, rinnovi).'],
                    'APP_ENV' => ['label' => 'Ambiente', 'tipo' => 'select', 'opzioni' => ['production' => 'Produzione', 'staging' => 'Staging', 'local' => 'Sviluppo locale'], 'rules' => ['required', Rule::in(['production', 'staging', 'local'])], 'default' => 'production'],
                    'APP_DEBUG' => ['label' => 'Modalità debug', 'tipo' => 'bool', 'rules' => $bool, 'help' => 'Mostra i dettagli degli errori. Da tenere SEMPRE spenta in produzione.'],
                    'APP_LOCALE' => ['label' => 'Lingua', 'tipo' => 'select', 'opzioni' => ['it' => 'Italiano', 'en' => 'English'], 'rules' => ['required', Rule::in(['it', 'en'])], 'default' => 'it'],
                    'LOG_LEVEL' => ['label' => 'Livello di log', 'tipo' => 'select', 'opzioni' => ['debug' => 'debug', 'info' => 'info', 'notice' => 'notice', 'warning' => 'warning', 'error' => 'error', 'critical' => 'critical'], 'rules' => ['required', Rule::in(['debug', 'info', 'notice', 'warning', 'error', 'critical'])], 'default' => 'debug'],
                ],
            ],
            'email' => [
                'titolo' => 'Email SMTP',
                'icona' => 'bi-envelope',
                'descrizione' => 'Server di posta di sistema: recupero password degli amministratori, notifiche di piattaforma e associazioni senza un proprio SMTP.',
                'campi' => [
                    'MAIL_MAILER' => ['label' => 'Metodo di invio', 'tipo' => 'select', 'opzioni' => ['smtp' => 'SMTP', 'sendmail' => 'Sendmail del server', 'log' => 'Solo log (nessun invio, per sviluppo)'], 'rules' => ['required', Rule::in(['smtp', 'sendmail', 'log'])], 'default' => 'log'],
                    'MAIL_HOST' => ['label' => 'Host SMTP', 'tipo' => 'text', 'rules' => ['nullable', 'required_if:MAIL_MAILER,smtp', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'], 'placeholder' => 'smtp.tuoprovider.it'],
                    'MAIL_PORT' => ['label' => 'Porta', 'tipo' => 'number', 'rules' => $int(1, 65535), 'default' => '587'],
                    'MAIL_SCHEME' => ['label' => 'Crittografia', 'tipo' => 'select', 'opzioni' => ['' => 'Automatica (STARTTLS se disponibile, porta 587)', 'smtps' => 'SSL/TLS implicito (porta 465)', 'smtp' => 'Nessuna'], 'rules' => ['nullable', Rule::in(['', 'smtp', 'smtps'])]],
                    'MAIL_USERNAME' => ['label' => 'Utente SMTP', 'tipo' => 'text', 'rules' => ['nullable', 'string', 'max:255']],
                    'MAIL_PASSWORD' => ['label' => 'Password SMTP', 'tipo' => 'password', 'segreto' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    'MAIL_FROM_ADDRESS' => ['label' => 'Email mittente', 'tipo' => 'email', 'rules' => ['required', 'email', 'max:255'], 'placeholder' => 'noreply@tuodominio.it'],
                    'MAIL_FROM_NAME' => ['label' => 'Nome mittente', 'tipo' => 'text', 'rules' => ['required', 'string', 'max:100'], 'default' => 'Gestione Associazioni'],
                ],
            ],
            'sicurezza' => [
                'titolo' => 'Sicurezza accessi',
                'icona' => 'bi-shield-lock',
                'descrizione' => 'Login, "Ricordami", recupero password, robustezza delle password e protezioni dei form.',
                'campi' => [
                    'AUTH_LOGIN_ATTEMPTS' => ['label' => 'Tentativi di login al minuto', 'tipo' => 'number', 'rules' => $int(3, 30), 'default' => '5', 'help' => 'Per combinazione email + IP; superata la soglia il login si blocca per un minuto.'],
                    'AUTH_REMEMBER_DAYS' => ['label' => 'Durata "Ricordami" (giorni)', 'tipo' => 'number', 'rules' => $int(1, 365), 'default' => '30'],
                    'AUTH_RESET_MINUTES' => ['label' => 'Validità link di recupero (minuti)', 'tipo' => 'number', 'rules' => $int(5, 1440), 'default' => '60'],
                    'ZXCVBN_MIN_SCORE' => ['label' => 'Robustezza minima delle password', 'tipo' => 'select', 'opzioni' => ['2' => '2 — sufficiente', '3' => '3 — buona (consigliata)', '4' => '4 — molto alta'], 'rules' => ['required', Rule::in(['2', '3', '4'])], 'default' => '3', 'help' => 'Punteggio zxcvbn richiesto per amministratori, soci e account pubblici.'],
                    'SESSION_LIFETIME' => ['label' => 'Scadenza sessione per inattività (minuti)', 'tipo' => 'number', 'rules' => $int(5, 10080), 'default' => '120'],
                    'SESSION_ENCRYPT' => ['label' => 'Cifra i dati di sessione', 'tipo' => 'bool', 'rules' => $bool],
                    'HONEYPOT_ENABLED' => ['label' => 'Protezione antispam (honeypot) sui form pubblici', 'tipo' => 'bool', 'rules' => $bool, 'default' => 'true'],
                    'CSP_ENABLED' => ['label' => 'Content Security Policy', 'tipo' => 'bool', 'rules' => $bool, 'default' => 'true'],
                    'ACTIVITYLOG_ENABLED' => ['label' => 'Registro attività', 'tipo' => 'bool', 'rules' => $bool, 'default' => 'true'],
                ],
            ],
            'database' => [
                'titolo' => 'Database',
                'icona' => 'bi-database',
                'descrizione' => 'Connessione MySQL/MariaDB. Prima del salvataggio la connessione viene sempre verificata.',
                'campi' => [
                    'DB_HOST' => ['label' => 'Host', 'tipo' => 'text', 'rules' => ['required', 'string', 'max:255'], 'default' => '127.0.0.1'],
                    'DB_PORT' => ['label' => 'Porta', 'tipo' => 'number', 'rules' => $int(1, 65535), 'default' => '3306'],
                    'DB_DATABASE' => ['label' => 'Nome database', 'tipo' => 'text', 'rules' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$\-]+$/']],
                    'DB_USERNAME' => ['label' => 'Utente', 'tipo' => 'text', 'rules' => ['required', 'string', 'max:255']],
                    'DB_PASSWORD' => ['label' => 'Password', 'tipo' => 'password', 'segreto' => true, 'rules' => ['nullable', 'string', 'max:255']],
                ],
            ],
            'backup' => [
                'titolo' => 'Backup e aggiornamenti',
                'icona' => 'bi-cloud-arrow-down',
                'descrizione' => 'Notifiche di backup, salute e job falliti, cifratura dei backup, accesso a GitHub per gli aggiornamenti in-app.',
                'campi' => [
                    'BACKUP_NOTIFICATION_MAIL' => ['label' => 'Email notifiche backup e salute', 'tipo' => 'email', 'rules' => ['nullable', 'email', 'max:255']],
                    'FAILED_JOB_EMAILS' => ['label' => 'Email avvisi job falliti', 'tipo' => 'text', 'rules' => ['nullable', 'string', 'max:500', $this->elencoEmail()], 'placeholder' => 'admin@tuodominio.it, tecnico@tuodominio.it', 'help' => 'Separate da virgola. Vuoto = email notifiche backup. Al massimo un avviso ogni 15 minuti per tipo di job.'],
                    'FAILED_JOB_MONITOR_ENABLED' => ['label' => 'Avvisi job falliti attivi', 'tipo' => 'bool', 'rules' => $bool, 'default' => 'true'],
                    'BACKUP_ARCHIVE_PASSWORD' => ['label' => 'Password archivi di backup', 'tipo' => 'password', 'segreto' => true, 'rules' => ['nullable', 'string', 'max:255'], 'help' => 'Se impostata, gli zip di backup vengono cifrati. Conservala altrove: senza non potrai ripristinarli.'],
                    'UPDATE_GITHUB_TOKEN' => ['label' => 'Token GitHub', 'tipo' => 'password', 'segreto' => true, 'rules' => ['nullable', 'string', 'max:255'], 'help' => 'Facoltativo: evita il limite di 60 richieste/ora di GitHub nel controllo aggiornamenti.'],
                    'UPDATE_SELF_ENABLED' => ['label' => 'Aggiornamento in-app abilitato', 'tipo' => 'bool', 'rules' => $bool, 'default' => 'true'],
                ],
            ],
        ];
    }

    /**
     * Converte l'input validato nelle modifiche al .env.
     * Booleani come true/false; segreti lasciati vuoti = invariati (salvo "rimuovi").
     *
     * @param  array<string, array<string, mixed>>  $campi
     * @param  array<string, mixed>  $dati
     * @return array<string, string>
     */
    private function modificheDaInput(array $campi, array $dati, Request $request): array
    {
        $attuali = $this->env->read();
        $modifiche = [];

        foreach ($campi as $chiave => $campo) {
            if ($campo['tipo'] === 'bool') {
                $modifiche[$chiave] = $request->boolean($chiave) ? 'true' : 'false';
            } elseif (! empty($campo['segreto'])) {
                $nuovo = (string) ($dati[$chiave] ?? '');
                $modifiche[$chiave] = $request->boolean($chiave.'_rimuovi') ? '' : ($nuovo !== '' ? $nuovo : (string) ($attuali[$chiave] ?? ''));
            } else {
                $valore = trim((string) ($dati[$chiave] ?? ''));
                $modifiche[$chiave] = $chiave === 'APP_URL' ? rtrim($valore, '/') : $valore;
            }
        }

        return $modifiche;
    }

    /**
     * @param  array<string, string>  $cfg
     */
    private function verificaDatabase(array $cfg): ?string
    {
        try {
            $pdo = new PDO(
                'mysql:host='.$cfg['DB_HOST'].';port='.$cfg['DB_PORT'].';dbname='.$cfg['DB_DATABASE'].';charset=utf8mb4',
                $cfg['DB_USERNAME'],
                $cfg['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'utenti'");
            $haUtenti = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
            if (! $haUtenti) {
                return 'Connessione riuscita, ma il database "'.$cfg['DB_DATABASE'].'" non contiene la tabella utenti: salvandolo non potresti più accedere.';
            }

            return null;
        } catch (PDOException $e) {
            return 'Connessione fallita: '.$e->getMessage();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $campi
     * @return list<string>
     */
    private function campiSegreti(array $campi): array
    {
        return array_keys(array_filter($campi, fn (array $c) => ! empty($c['segreto'])));
    }

    /**
     * Problemi di configurazione da segnalare in cima alla pagina.
     *
     * @return list<string>
     */
    private function avvisi(): array
    {
        $avvisi = [];
        if (config('app.debug') && app()->isProduction()) {
            $avvisi[] = 'La modalità debug è attiva in produzione: gli errori mostrano dettagli interni.';
        }
        if (config('mail.default') === 'log') {
            $avvisi[] = 'Le email non vengono inviate (mailer "log"): configura l\'SMTP di sistema per il recupero password.';
        }
        if (! str_starts_with((string) config('app.url'), 'https://') && app()->isProduction()) {
            $avvisi[] = 'APP_URL non usa HTTPS: in produzione i link nelle email devono essere https.';
        }

        return $avvisi;
    }

    /**
     * @return array<string, mixed>
     */
    private function infoSistema(): array
    {
        $dbVersione = '';
        try {
            $dbVersione = (string) DB::selectOne('SELECT VERSION() AS v')?->v;
        } catch (Throwable) {
            // database non raggiungibile: mostrato come "non disponibile"
        }

        $gestite = collect($this->sezioni())->flatMap(fn (array $s) => array_keys($s['campi']))->all();
        $altre = array_diff_key($this->env->read(), array_flip(array_merge($gestite, ['APP_KEY', 'DB_CONNECTION'])));

        return [
            'server' => [
                'PHP' => PHP_VERSION.' ('.PHP_SAPI.')',
                'Laravel' => app()->version(),
                'Database' => $dbVersione !== '' ? $dbVersione : 'non disponibile',
                'Ambiente' => app()->environment(),
                'Cache config' => app()->configurationIsCached() ? 'attiva' : 'non attiva',
                'Code (queue)' => config('queue.default'),
                'Sessioni' => config('session.driver'),
                'Cache' => config('cache.default'),
                'Fuso orario' => config('app.timezone'),
            ],
            'estensioni' => collect(['pdo_mysql', 'openssl', 'mbstring', 'intl', 'gd', 'zip', 'curl', 'bcmath'])
                ->mapWithKeys(fn (string $e) => [$e => extension_loaded($e)])->all(),
            'env' => [
                'percorso' => $this->env->path(),
                'scrivibile' => $this->env->isWritable(),
                'backup' => $this->env->backupCount(),
                'app_key' => filled(config('app.key')),
            ],
            'altre' => array_keys($altre),
        ];
    }

    /** Regola: elenco di email separate da virgola. */
    private function elencoEmail(): \Closure
    {
        return function (string $attributo, mixed $valore, \Closure $fail): void {
            foreach (array_filter(array_map('trim', explode(',', (string) $valore))) as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $fail("\"{$email}\" non è un indirizzo email valido.");
                }
            }
        };
    }

    private function soloSuperAdmin(Request $request): void
    {
        $utente = $request->user();
        abort_unless($utente instanceof Utente && $utente->isSuperAdmin(), 403);
    }
}
