<?php

namespace Tests\Feature\Admin;

use App\Services\Installer\SchemaSql;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * Aggiornamento vero, senza doppi di prova: un pacchetto pubblicato viene
 * installato su un database vuoto e aggiornato da app:aggiorna in processi
 * separati, con dati, upload e un sito che risponde.
 *
 * Due salti, perché sono due codici diversi:
 *  1. da UPDATE_E2E_PACCHETTO_DA a UPDATE_E2E_PACCHETTO_A: gira l'updater
 *     della versione VECCHIA, l'unico che le installazioni esistenti hanno;
 *  2. da UPDATE_E2E_PACCHETTO_A alla stessa versione con la patch +1: gira
 *     l'updater appena pubblicato, quello degli aggiornamenti futuri. Qui si
 *     interroga il sito durante l'aggiornamento: deve rispondere 503, mai 500.
 *
 * Si attiva con UPDATE_E2E_PACCHETTO_DA, UPDATE_E2E_PACCHETTO_A e
 * INSTALLER_TEST_MYSQL_DSN. Il nome non contiene "Mysql" di proposito: il job
 * dei database lancia --filter=Mysql --fail-on-skipped e non ha i pacchetti.
 */
class AggiornamentoPacchettiRealiTest extends TestCase
{
    /** Tabelle di dati dell'associazione: il conteggio non deve cambiare. */
    private const TABELLE_DATI = ['utenti', 'associazioni', 'soci', 'tessere', 'quote', 'eventi', 'prenotazioni', 'ricevute', 'riunioni'];

    private const INSTALLA = <<<'PHP'
    <?php
    [, $root, $database] = $argv;
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $installer = app(App\Services\Installer\InstallerService::class);
    $installer->installaSchema([
        'host' => getenv('E2E_DB_HOST'), 'port' => (int) getenv('E2E_DB_PORT'), 'database' => $database,
        'username' => getenv('E2E_DB_USER'), 'password' => getenv('E2E_DB_PASSWORD'),
    ]);
    $installer->createAdmin([
        'associazione_nome' => 'APS Aggiornamento', 'associazione_email' => 'info@aggiornamento.test',
        'nome' => 'Admin', 'cognome' => 'Prova', 'email' => 'admin@aggiornamento.test', 'password' => 'Aggiornamento-di-prova-1',
    ]);
    $installer->seedDemo();
    $installer->markInstalled();
    PHP;

    private string $lavoro;

    private string $root;

    private string $database;

    /** @var array{host: string, port: int, username: string, password: string} */
    private array $cfg;

    private PDO $server;

    private ?Process $sito = null;

    private int $porta;

    protected function setUp(): void
    {
        parent::setUp();

        $da = (string) env('UPDATE_E2E_PACCHETTO_DA', '');
        $a = (string) env('UPDATE_E2E_PACCHETTO_A', '');
        $dsn = (string) env('INSTALLER_TEST_MYSQL_DSN', '');
        if ($da === '' || $a === '' || $dsn === '') {
            $this->markTestSkipped('Imposta UPDATE_E2E_PACCHETTO_DA, UPDATE_E2E_PACCHETTO_A e INSTALLER_TEST_MYSQL_DSN per aggiornare un pacchetto reale.');
        }

        $p = parse_url($dsn) ?: [];
        $base = ltrim((string) ($p['path'] ?? ''), '/');
        if (! str_contains($base, 'test')) {
            $this->fail('Il database di prova deve contenere "test" nel nome.');
        }
        $this->database = $base.'_aggiornamento';
        $this->cfg = [
            'host' => $p['host'] ?? '127.0.0.1', 'port' => (int) ($p['port'] ?? 3306),
            'username' => urldecode($p['user'] ?? 'root'), 'password' => urldecode($p['pass'] ?? ''),
        ];
        $this->server = new PDO(sprintf('mysql:host=%s;port=%d', $this->cfg['host'], $this->cfg['port']), $this->cfg['username'], $this->cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->lavoro = sys_get_temp_dir().'/aggiornamento_e2e_'.uniqid();
        File::ensureDirectoryExists($this->lavoro);
        $this->root = $this->lavoro.'/gestionale-associazioni';
    }

    protected function tearDown(): void
    {
        $this->sito?->stop(2);
        if (isset($this->server)) {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
        if (isset($this->lavoro)) {
            File::deleteDirectory($this->lavoro);
        }
        parent::tearDown();
    }

    public function test_il_pacchetto_vecchio_si_aggiorna_al_nuovo_e_il_nuovo_al_successivo(): void
    {
        $da = (string) env('UPDATE_E2E_PACCHETTO_DA');
        $a = (string) env('UPDATE_E2E_PACCHETTO_A');

        $this->installa($da);
        $this->aggiungiDati();
        $prima = $this->conteggi();
        $envPrima = (string) file_get_contents($this->root.'/.env');
        $this->avviaSito();
        $this->assertSame(200, $this->stato('/login'), 'Il sito installato deve rispondere prima di aggiornarlo');

        // 1. L'updater della versione vecchia installa il pacchetto nuovo
        $versioneNuova = $this->versioneDi($a);
        $esito = $this->processo(['php', $this->root.'/artisan', 'app:aggiorna', '--pacchetto='.$a, '--force']);
        $esito->run();
        $this->assertTrue($esito->isSuccessful(), $esito->getOutput().$esito->getErrorOutput());
        $this->verificaInstallazione($versioneNuova, $prima, $envPrima);

        // 2. L'updater appena installato si aggiorna da solo, con il sito acceso
        $successiva = $this->patchSuccessiva($versioneNuova);
        $pacchetto = $this->pacchettoConVersione($a, $successiva);
        $aggiornamento = $this->processo(['php', $this->root.'/artisan', 'app:aggiorna', '--pacchetto='.$pacchetto, '--force']);
        $aggiornamento->start();
        $risposte = [];
        while ($aggiornamento->isRunning()) {
            $risposte[] = $this->stato('/login');
            usleep(200_000);
        }

        $this->assertTrue($aggiornamento->isSuccessful(), $aggiornamento->getOutput().$aggiornamento->getErrorOutput());
        $this->assertContains(503, $risposte, 'Durante l\'aggiornamento il sito deve essere in manutenzione');
        $this->assertNotContains(500, $risposte, 'La manutenzione deve mostrare la pagina 503, non un errore');
        $this->verificaInstallazione($successiva, $prima, $envPrima);

        $esiti = $this->db()->query("SELECT to_version, stato, backup_path FROM update_logs WHERE stato = 'completato'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEqualsCanonicalizing([$versioneNuova, $successiva], array_column($esiti, 'to_version'));
        foreach ($esiti as $esito) {
            $this->assertNotEmpty($esito['backup_path'], 'Senza backup del database non si aggiorna');
        }
    }

    // ------------------------------------------------------------------

    private function installa(string $pacchetto): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($pacchetto) === true, 'Pacchetto illeggibile: '.$pacchetto);
        $zip->extractTo($this->lavoro);
        $zip->close();
        chmod($this->root.'/artisan', 0755);

        $password = str_replace(['\\', '"'], ['\\\\', '\\"'], $this->cfg['password']);
        $this->porta = $this->portaLibera();
        $valori = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => 'http://127.0.0.1:'.$this->porta, 'QUEUE_CONNECTION' => 'sync',
            'DB_HOST' => $this->cfg['host'], 'DB_PORT' => (string) $this->cfg['port'], 'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $this->cfg['username'], 'DB_PASSWORD' => '"'.$password.'"',
        ];
        $env = (string) file_get_contents($this->root.'/.env.example');
        foreach ($valori as $chiave => $valore) {
            $env = (string) preg_replace_callback("/^{$chiave}=.*$/m", fn (): string => $chiave.'='.$valore, $env);
        }
        File::put($this->root.'/.env', $env);

        $script = $this->lavoro.'/installa.php';
        File::put($script, trim(self::INSTALLA));
        $esito = $this->processo(['php', $script, $this->root, $this->database], [
            'E2E_DB_HOST' => $this->cfg['host'], 'E2E_DB_PORT' => (string) $this->cfg['port'],
            'E2E_DB_USER' => $this->cfg['username'], 'E2E_DB_PASSWORD' => $this->cfg['password'],
        ]);
        $esito->run();
        $this->assertTrue($esito->isSuccessful(), 'Installazione fallita: '.$esito->getOutput().$esito->getErrorOutput());
    }

    /** Ciò che un aggiornamento non deve toccare, compreso un doppione di tessera. */
    private function aggiungiDati(): void
    {
        File::ensureDirectoryExists($this->root.'/public/uploads');
        File::put($this->root.'/public/uploads/foto-socio.jpg', 'foto');
        File::put($this->root.'/storage/app/documento.txt', 'documento');

        // Due tessere non annullate dello stesso socio e anno: il vincolo
        // unique della 0.2.0 non può nascere, ma la migration deve reggere
        $tessera = $this->db()->query("SELECT * FROM tessere WHERE stato <> 'Annullata' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($tessera, 'I dati dimostrativi devono contenere una tessera');
        $tessera['id'] = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFFFFFF));
        foreach (['numero_tessera', 'qr_token', 'codice'] as $colonna) {
            if (isset($tessera[$colonna])) {
                $tessera[$colonna] .= '-D';
            }
        }
        $colonne = array_keys($tessera);
        $this->db()->prepare(sprintf(
            'INSERT INTO tessere (`%s`) VALUES (%s)',
            implode('`, `', $colonne),
            implode(', ', array_fill(0, count($colonne), '?')),
        ))->execute(array_values($tessera));
    }

    /**
     * @param  array<string, int>  $prima
     */
    private function verificaInstallazione(string $versione, array $prima, string $envPrima): void
    {
        $this->assertSame($versione, trim((string) file_get_contents($this->root.'/VERSION')));
        $this->assertFileDoesNotExist($this->root.'/storage/framework/down', 'La manutenzione deve finire');
        $this->assertSame([], File::directories($this->root.'/storage/app/updates'), 'L\'area di lavoro deve restare pulita');

        $this->assertSame($envPrima, file_get_contents($this->root.'/.env'));
        $this->assertSame('foto', file_get_contents($this->root.'/public/uploads/foto-socio.jpg'));
        $this->assertSame('documento', file_get_contents($this->root.'/storage/app/documento.txt'));
        $this->assertSame($prima, $this->conteggi());

        // Ogni migration del pacchetto registrata: niente resta in sospeso
        $attese = SchemaSql::migrazioniDelProgetto($this->root.'/database/migrations');
        $eseguite = $this->db()->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([], array_values(array_diff($attese, $eseguite)));

        $this->assertSame(200, $this->stato('/login'), 'Dopo l\'aggiornamento il sito deve rispondere');
    }

    /** @return array<string, int> */
    private function conteggi(): array
    {
        $conteggi = [];
        foreach (self::TABELLE_DATI as $tabella) {
            $conteggi[$tabella] = (int) $this->db()->query("SELECT COUNT(*) FROM `{$tabella}`")->fetchColumn();
        }

        return $conteggi;
    }

    private function db(): PDO
    {
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $this->cfg['host'], $this->cfg['port'], $this->database), $this->cfg['username'], $this->cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function versioneDi(string $pacchetto): string
    {
        $zip = new ZipArchive;
        $zip->open($pacchetto);
        $versione = trim((string) $zip->getFromName('gestionale-associazioni/VERSION'));
        $zip->close();
        $this->assertNotSame('', $versione, 'Il pacchetto non dichiara la versione');

        return $versione;
    }

    private function patchSuccessiva(string $versione): string
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', explode('-', $versione)[0]));

        return sprintf('%d.%d.%d', $major, $minor, $patch + 1);
    }

    /** Copia del pacchetto che dichiara un'altra versione: stesso codice, stesso updater. */
    private function pacchettoConVersione(string $pacchetto, string $versione): string
    {
        $copia = $this->lavoro.'/successivo.zip';
        File::copy($pacchetto, $copia);
        $zip = new ZipArchive;
        $zip->open($copia);
        $zip->addFromString('gestionale-associazioni/VERSION', $versione."\n", ZipArchive::FL_OVERWRITE);
        $zip->close();

        return $copia;
    }

    private function avviaSito(): void
    {
        $this->sito = $this->processo(
            ['php', '-S', '127.0.0.1:'.$this->porta, '-t', '.', '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
            [],
            $this->root.'/public',
        );
        $this->sito->start();

        for ($tentativo = 0; $tentativo < 50; $tentativo++) {
            if ($this->stato('/login') !== null) {
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('Il sito di prova non è partito: '.$this->sito->getErrorOutput());
    }

    /** Codice HTTP di una pagina, null se il server non risponde. */
    private function stato(string $percorso): ?int
    {
        $contesto = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
        $corpo = @file_get_contents('http://127.0.0.1:'.$this->porta.$percorso, false, $contesto);
        if ($corpo === false) {
            return null;
        }

        return preg_match('/^HTTP\/\S+\s+(\d{3})/', $http_response_header[0] ?? '', $m) === 1 ? (int) $m[1] : null;
    }

    private function portaLibera(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $nome = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) strrchr($nome, ':'), 1);
    }

    /**
     * Processo con un ambiente pulito: quello di phpunit.xml (SQLite in
     * memoria, APP_ENV=testing) scavalcherebbe il .env dell'installazione.
     *
     * @param  list<string>  $comando
     * @param  array<string, string>  $ambiente
     */
    private function processo(array $comando, array $ambiente = [], ?string $cartella = null): Process
    {
        $comando[0] = $comando[0] === 'php' ? PHP_BINARY : $comando[0];
        $variabili = ['PATH='.(string) getenv('PATH'), 'HOME='.(string) getenv('HOME')];
        foreach ($ambiente as $chiave => $valore) {
            $variabili[] = $chiave.'='.$valore;
        }

        return new Process(array_merge(['env', '-i'], $variabili, $comando), $cartella ?? $this->root, null, null, 600);
    }
}
