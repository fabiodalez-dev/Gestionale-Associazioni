<?php

namespace Tests\Feature\Installer;

use App\Models\Utente;
use App\Services\Installer\InstallerService;
use App\Services\Installer\SchemaSql;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Prova REALE dell'iniezione dello schema in un database MySQL vuoto, come
 * la fa l'installer web. Richiede un server MySQL raggiungibile e si attiva
 * solo con la variabile d'ambiente INSTALLER_TEST_MYSQL_DSN, es.
 *
 *   INSTALLER_TEST_MYSQL_DSN="mysql://root:segreta@127.0.0.1:3306/gestione_installer_test" php artisan test --filter=InstallaSchemaMysqlTest
 *
 * Il database indicato viene CREATO e CANCELLATO dal test: usa un nome dedicato.
 */
class InstallaSchemaMysqlTest extends TestCase
{
    /** @var array{host: string, port: int, database: string, username: string, password: string} */
    private array $cfg;

    private PDO $server;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = (string) env('INSTALLER_TEST_MYSQL_DSN', '');
        if ($dsn === '') {
            $this->markTestSkipped('Imposta INSTALLER_TEST_MYSQL_DSN per provare l\'installazione dello schema su MySQL.');
        }

        $parti = parse_url($dsn);
        if ($parti === false || ($parti['scheme'] ?? '') !== 'mysql' || blank($parti['path'] ?? '')) {
            $this->fail('INSTALLER_TEST_MYSQL_DSN non valido: atteso mysql://utente:password@host:porta/database');
        }

        $database = ltrim($parti['path'], '/');
        if (! str_contains($database, 'test')) {
            $this->fail('Per sicurezza il database di prova deve contenere "test" nel nome: '.$database);
        }

        $this->cfg = [
            'host' => $parti['host'] ?? '127.0.0.1',
            'port' => (int) ($parti['port'] ?? 3306),
            'database' => $database,
            'username' => urldecode($parti['user'] ?? 'root'),
            'password' => urldecode($parti['pass'] ?? ''),
        ];

        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%d', $this->cfg['host'], $this->cfg['port']),
            $this->cfg['username'],
            $this->cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->server->exec("DROP DATABASE IF EXISTS `{$database}`");
        $this->server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Il seeder dei ruoli svuota la cache dei permessi: deve restare in memoria
        config(['cache.default' => 'array']);
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            DB::purge('mysql');
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->cfg['database']}`");
        }

        parent::tearDown();
    }

    public function test_lo_schema_viene_iniettato_nel_database_vuoto_e_l_app_e_pronta(): void
    {
        $installer = app(InstallerService::class);

        $esito = $installer->testDatabase($this->cfg);
        $this->assertTrue($esito['ok'], (string) $esito['error']);
        $this->assertSame(0, $esito['tables'], 'Il database di prova deve partire vuoto');

        $risultato = $installer->installaSchema($this->cfg);

        $sql = (string) file_get_contents(config('installer.schema_file'));
        $attese = SchemaSql::tabelle($sql);
        $this->assertSame(count($attese), $risultato['tabelle']);

        // Tutte le tabelle del dump esistono davvero
        $this->assertSame(count($attese), $installer->testDatabase($this->cfg)['tables']);
        $this->assertTrue($installer->databaseReady());

        // Il registro delle migration è completo: `migrate` non ha nulla da fare
        $registrate = DB::connection('mysql')->table('migrations')->orderBy('migration')->pluck('migration')->all();
        $this->assertSame(SchemaSql::migrazioniDelProgetto(database_path('migrations')), $registrate);
        Artisan::call('migrate', ['--force' => true]);
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());

        // Ruoli e permessi allineati, admin creabile come nel wizard
        $this->assertGreaterThan(0, DB::connection('mysql')->table('roles')->count());
        $this->assertFalse($installer->adminExists());

        $admin = $installer->createAdmin([
            'associazione_nome' => 'APS Prova MySQL',
            'associazione_email' => 'info@prova.test',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario@prova.test',
            'password' => 'SuperSegreta123',
        ]);

        $this->assertInstanceOf(Utente::class, $admin);
        $this->assertTrue($installer->adminExists());
        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }

    public function test_reinstallare_su_un_database_con_le_tabelle_dell_app_le_ricrea_vuote(): void
    {
        $installer = app(InstallerService::class);
        $installer->installaSchema($this->cfg);
        // Installazione interrotta: tabelle e qualche dato, ma nessun super admin
        DB::connection('mysql')->table('associazioni')->insert([
            'id' => (string) Str::uuid(), 'nome' => 'Parziale', 'email' => 'p@prova.test',
            'attiva' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Una tabella estranea all'app deve sopravvivere alla reinstallazione
        DB::connection('mysql')->statement('CREATE TABLE `tabella_di_altri` (`id` int)');

        $esito = $installer->testDatabase($this->cfg);
        $this->assertGreaterThan(0, $esito['tables']);
        $this->assertFalse($esito['installata'], 'Senza super admin il database non conta come installato');

        $installer->installaSchema($this->cfg);

        $this->assertSame(0, DB::connection('mysql')->table('associazioni')->count());
        $this->assertTrue(Schema::connection('mysql')->hasTable('tabella_di_altri'));
    }

    public function test_un_database_con_super_admin_viene_riconosciuto_come_installato(): void
    {
        $installer = app(InstallerService::class);
        $installer->installaSchema($this->cfg);
        $this->assertFalse($installer->testDatabase($this->cfg)['installata']);

        $installer->createAdmin([
            'associazione_nome' => 'Vecchia', 'associazione_email' => 'v@prova.test',
            'nome' => 'A', 'cognome' => 'B', 'email' => 'ab@prova.test', 'password' => 'SuperSegreta123',
        ]);

        $this->assertTrue($installer->testDatabase($this->cfg)['installata']);
    }
}
