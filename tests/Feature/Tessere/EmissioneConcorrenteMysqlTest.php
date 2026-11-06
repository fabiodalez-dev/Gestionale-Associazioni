<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Installer\InstallerService;
use App\Services\Tessere\TesseraIssuer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Emissione delle tessere al pagamento con processi concorrenti su un vero
 * MariaDB/MySQL (SQLite in memoria non riproduce lock e snapshot InnoDB).
 *
 * Si attiva solo con INSTALLER_TEST_MYSQL_DSN, es.
 *   INSTALLER_TEST_MYSQL_DSN="mysql://gest:gest@127.0.0.1:3306/flussi_schema_test"
 * e usa un database dedicato "<database>_tessere_conc", creato e cancellato
 * dal test. Ogni worker è un processo PHP separato che chiama
 * PaymentService::onPaymentCompleted (o TesseraIssuer::generaMassivo); un
 * listener sulle query allarga la finestra di gara in modo deterministico.
 */
class EmissioneConcorrenteMysqlTest extends TestCase
{
    /** @var array{host: string, port: int, database: string, username: string, password: string} */
    private array $cfg;

    private PDO $server;

    private string $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = (string) env('INSTALLER_TEST_MYSQL_DSN', '');
        if ($dsn === '') {
            $this->markTestSkipped('Imposta INSTALLER_TEST_MYSQL_DSN per provare l\'emissione concorrente su MySQL.');
        }
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open non disponibile.');
        }

        $p = parse_url($dsn) ?: [];
        $database = ltrim((string) ($p['path'] ?? ''), '/').'_tessere_conc';
        $this->assertStringContainsString('test', $database, 'Per sicurezza il database di prova contiene "test" nel nome');

        $this->cfg = [
            'host' => $p['host'] ?? '127.0.0.1', 'port' => (int) ($p['port'] ?? 3306), 'database' => $database,
            'username' => urldecode($p['user'] ?? 'root'), 'password' => urldecode($p['pass'] ?? ''),
        ];
        $this->server = new PDO(sprintf('mysql:host=%s;port=%d', $this->cfg['host'], $this->cfg['port']), $this->cfg['username'], $this->cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec("DROP DATABASE IF EXISTS `{$database}`");
        $this->server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        app(InstallerService::class)->installaSchema($this->cfg);
        config(['database.default' => 'mysql']);
        DB::purge('mysql');

        $this->worker = sys_get_temp_dir().'/worker_tessere_'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($this->worker, $this->sorgenteWorker());
    }

    protected function tearDown(): void
    {
        if (isset($this->worker) && is_file($this->worker)) {
            @unlink($this->worker);
        }
        if (isset($this->server)) {
            DB::purge('mysql');
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->cfg['database']}`");
        }
        parent::tearDown();
    }

    public function test_due_pagamenti_concorrenti_dello_stesso_socio_emettono_una_sola_tessera(): void
    {
        $associazione = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id, 'stato' => Socio::STATO_ATTIVO]);
        $quote = Quota::factory()->count(2)->create([
            'socio_id' => $socio->id,
            'associazione_id' => $associazione->id,
            'anno' => 2031,
        ]);

        // Il primo worker tiene aperta la transazione dopo l'insert della
        // tessera: il secondo arriva al controllo di esistenza prima del commit
        $esiti = $this->avviaWorker('tessera', $associazione->id, $quote->pluck('id')->all());

        $this->assertSame(['OK', 'OK'], $esiti);
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', 2031)->count());
        $this->assertSame(2, Quota::allTenants()->where('socio_id', $socio->id)->whereNotNull('data_pagamento')->count());
    }

    public function test_prima_tessera_dell_anno_con_pagamenti_concorrenti_di_soci_diversi(): void
    {
        $associazione = Associazione::factory()->create();
        $quote = [];
        foreach (range(1, 4) as $_) {
            $socio = Socio::factory()->create(['associazione_id' => $associazione->id, 'stato' => Socio::STATO_ATTIVO]);
            $quote[] = Quota::factory()->create([
                'socio_id' => $socio->id,
                'associazione_id' => $associazione->id,
                'anno' => 2031,
            ])->id;
        }

        // Tutti i worker trovano assente la riga del contatore 2031 prima
        // che uno di loro la crei
        $esiti = $this->avviaWorker('contatore', $associazione->id, $quote);

        $this->assertSame(['OK', 'OK', 'OK', 'OK'], $esiti);
        $numeri = Tessera::allTenants()->where('associazione_id', $associazione->id)->where('anno_validita', 2031)->pluck('numero_tessera')->sort()->values()->all();
        $this->assertSame(['20310001', '20310002', '20310003', '20310004'], $numeri);
    }

    public function test_generazione_massiva_e_pagamento_concorrente_di_un_socio_del_lotto_non_vanno_in_deadlock(): void
    {
        $associazione = Associazione::factory()->create();
        $soci = collect(range(1, 3))
            ->map(fn () => Socio::factory()->create(['associazione_id' => $associazione->id, 'stato' => Socio::STATO_ATTIVO]))
            ->sortBy('id')
            ->values();

        // L'ultimo socio del lotto (ordine per id) paga la quota 2031 mentre
        // la generazione massiva è già partita: prima (issue #21) il lotto
        // teneva il lock del contatore fino alla fine e il pagamento, con il
        // socio bloccato, lo aspettava; il lotto arrivato al socio restava a
        // sua volta in attesa della riga del socio → deadlock
        $quota = Quota::factory()->create([
            'socio_id' => $soci->last()->id,
            'associazione_id' => $associazione->id,
            'anno' => 2031,
        ]);

        $esiti = $this->avviaWorkerMisti($associazione->id, [
            ['massivo', 'massivo'],
            ['ritardato', $quota->id],
        ]);

        $this->assertMatchesRegularExpression('/^OK [23]$/', $esiti[0]);
        $this->assertSame('OK', $esiti[1]);
        foreach ($soci as $socio) {
            $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', 2031)->count());
        }
        $numeri = Tessera::allTenants()->where('associazione_id', $associazione->id)->where('anno_validita', 2031)->pluck('numero_tessera')->sort()->values()->all();
        $this->assertSame(['20310001', '20310002', '20310003'], $numeri);
        $this->assertNotNull($quota->fresh()->data_pagamento);
    }

    public function test_violazione_dentro_una_transazione_esterna_vale_come_gia_presente_anche_se_la_tessera_non_e_nello_snapshot(): void
    {
        $associazione = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id, 'stato' => Socio::STATO_ATTIVO]);
        $issuer = app(TesseraIssuer::class);

        // Seconda sessione: un altro processo (es. la generazione massiva)
        config(['database.connections.mysql_altro' => config('database.connections.mysql')]);

        DB::beginTransaction();
        try {
            // Transazione esterna (come onPaymentCompleted) che ha già letto
            // le tessere: lo snapshot REPEATABLE READ è fissato qui
            $this->assertSame(0, Tessera::allTenants()->where('socio_id', $socio->id)->count());

            DB::connection('mysql_altro')->table('tessere')->insert([
                'id' => (string) Str::uuid(), 'socio_id' => $socio->id, 'associazione_id' => $associazione->id,
                'numero_tessera' => '20310050', 'anno_validita' => 2031, 'anno_non_annullata' => 2031,
                'data_emissione' => '2031-01-10', 'data_scadenza' => '2031-12-31', 'tipo_scadenza' => 'solare',
                'stato' => Tessera::STATO_ATTIVA, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // Il controllo sotto lock legge lo snapshot e non vede la tessera
            // dell'altra sessione, l'insert viola il vincolo: la rilettura
            // bloccante la trova e l'emissione vale come "già presente"
            $this->assertNull($issuer->emettiSeNonPresente($socio, ['anno_validita' => 2031]));
        } finally {
            DB::rollBack();
            DB::purge('mysql_altro');
        }

        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', 2031)->count());
    }

    public function test_su_mariadb_la_migration_del_vincolo_socio_anno_conserva_i_doppioni_e_aggiunge_il_vincolo_quando_sono_sistemati(): void
    {
        $migration = require database_path('migrations/2026_11_05_120100_add_unique_socio_anno_to_tessere_table.php');
        $associazione = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id]);

        // Lo schema dell'installer ha già colonna e vincolo
        $this->assertTrue(Schema::hasIndex('tessere', 'uq_tessere_socio_anno', 'unique'));
        $migration->down();
        $this->assertFalse(Schema::hasIndex('tessere', 'uq_tessere_socio_anno'));
        $this->assertFalse(Schema::hasColumn('tessere', 'anno_non_annullata'));

        // Righe "pre-migration": senza la colonna del vincolo (insert diretto)
        foreach ([['D-1', 2030, 'Attiva'], ['D-2', 2030, 'Attiva'], ['D-3', 2031, 'Annullata'], ['D-4', 2031, 'Attiva']] as [$numero, $anno, $stato]) {
            DB::table('tessere')->insert([
                'id' => (string) Str::uuid(), 'socio_id' => $socio->id, 'associazione_id' => $associazione->id,
                'numero_tessera' => $numero, 'anno_validita' => $anno, 'data_emissione' => $anno.'-01-10',
                'data_scadenza' => $anno.'-12-31', 'tipo_scadenza' => 'solare', 'stato' => $stato,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();
        $this->assertFalse(Schema::hasIndex('tessere', 'uq_tessere_socio_anno'));
        $righe = DB::table('tessere')->where('socio_id', $socio->id)->orderBy('numero_tessera')->get();
        $this->assertSame(['D-1', 'D-2', 'D-3', 'D-4'], $righe->pluck('numero_tessera')->all());
        $this->assertSame([2030, 2030, null, 2031], $righe->pluck('anno_non_annullata')->map(fn ($a) => $a === null ? null : (int) $a)->all());

        Tessera::allTenants()->where('numero_tessera', 'D-2')->firstOrFail()->update(['stato' => Tessera::STATO_ANNULLATA]);
        $migration->up();
        $this->assertTrue(Schema::hasIndex('tessere', 'uq_tessere_socio_anno', 'unique'));

        // Il vincolo è attivo su MariaDB: duplicato annullato ammesso, secondo valido no
        Tessera::factory()->create(['socio_id' => $socio->id, 'associazione_id' => $associazione->id, 'anno_validita' => 2030, 'numero_tessera' => 'D-5', 'stato' => Tessera::STATO_ANNULLATA]);
        $this->expectException(UniqueConstraintViolationException::class);
        Tessera::factory()->create(['socio_id' => $socio->id, 'associazione_id' => $associazione->id, 'anno_validita' => 2030, 'numero_tessera' => 'D-6']);
    }

    /**
     * Avvia un worker per quota con partenza sincronizzata e ne raccoglie
     * l'esito (OK oppure ERR con il messaggio dell'eccezione).
     *
     * @param  list<string>  $quote
     * @return list<string>
     */
    private function avviaWorker(string $modo, string $associazioneId, array $quote): array
    {
        return $this->avviaWorkerMisti($associazioneId, array_map(fn (string $q) => [$modo, $q], $quote));
    }

    /**
     * Come avviaWorker, con un modo per worker.
     *
     * @param  list<array{0: string, 1: string}>  $lavori  [modo, quota id]
     * @return list<string>
     */
    private function avviaWorkerMisti(string $associazioneId, array $lavori): array
    {
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => $this->cfg['host'],
            'DB_PORT' => (string) $this->cfg['port'],
            'DB_DATABASE' => $this->cfg['database'],
            'DB_USERNAME' => $this->cfg['username'],
            'DB_PASSWORD' => $this->cfg['password'],
            'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ]);

        $avvio = (string) (microtime(true) + 3);
        $processi = [];
        $output = [];
        foreach ($lavori as [$modo, $quotaId]) {
            $comando = [PHP_BINARY, $this->worker, base_path(), $modo, $quotaId, $associazioneId, $avvio];
            $processi[] = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, base_path(), $env) ?: null;
            $output[] = $pipe;
        }

        $esiti = [];
        foreach ($processi as $i => $processo) {
            $this->assertNotNull($processo, 'Worker non avviato');
            $stdout = trim((string) stream_get_contents($output[$i][1]));
            $stderr = trim((string) stream_get_contents($output[$i][2]));
            proc_close($processo);
            $esiti[] = $stdout !== '' ? $stdout : 'ERR '.$stderr;
        }

        return $esiti;
    }

    private function sorgenteWorker(): string
    {
        return <<<'PHP'
<?php
[, $base, $modo, $quotaId, $associazioneId, $avvio] = $argv;
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Finestra di gara allargata: dopo l'insert della tessera (modo tessera)
// oppure dopo il controllo di esistenza del contatore (modo contatore)
Illuminate\Support\Facades\DB::listen(function ($query) use ($modo): void {
    $sql = strtolower($query->sql);
    if (in_array($modo, ['tessera', 'massivo'], true) && str_starts_with($sql, 'insert into `tessere`')) {
        usleep(500000);
    }
    if ($modo === 'contatore' && str_starts_with($sql, 'select exists') && str_contains($sql, '`tessera_counters`')) {
        usleep(500000);
    }
});

while (microtime(true) < (float) $avvio) {
    usleep(1000);
}

// Il pagamento "ritardato" parte quando il lotto ha già emesso la prima tessera
if ($modo === 'ritardato') {
    usleep(300000);
}

try {
    if ($modo === 'massivo') {
        $associazione = App\Models\Associazione::query()->findOrFail($associazioneId);
        echo 'OK '.app(App\Services\Tessere\TesseraIssuer::class)->generaMassivo($associazione, 2031);
        exit;
    }

    $ok = app(App\Services\Pagamenti\PaymentService::class)->onPaymentCompleted($quotaId, $associazioneId, 'stripe', 'tx_'.$quotaId);
    echo $ok ? 'OK' : 'KO';
} catch (Throwable $e) {
    echo 'ERR '.get_class($e).': '.substr($e->getMessage(), 0, 200);
}
PHP;
    }
}
