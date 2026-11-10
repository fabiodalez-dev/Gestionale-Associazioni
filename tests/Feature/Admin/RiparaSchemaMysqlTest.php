<?php

namespace Tests\Feature\Admin;

use App\Services\Installer\InstallerService;
use App\Services\Update\RiparaSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Riparazione REALE dello schema su MySQL/MariaDB (#29): tabelle e colonne
 * tolte a mano vengono ricreate da schema.sql, senza toccare il resto.
 * Si attiva con INSTALLER_TEST_MYSQL_DSN, come InstallaSchemaMysqlTest.
 */
class RiparaSchemaMysqlTest extends TestCase
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

    public function test_ricrea_tabelle_e_colonne_mancanti_e_poi_non_fa_nulla(): void
    {
        app(InstallerService::class)->installaSchema($this->cfg);
        $db = DB::connection('mysql');

        // Un aggiornamento interrotto: una colonna e una tabella mancano
        $db->statement('ALTER TABLE `soci` DROP COLUMN `note`');
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        $db->statement('DROP TABLE `tags`');
        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        // Una tabella estranea all'app resta intatta
        $db->statement('CREATE TABLE `tabella_di_altri` (`id` int)');

        $esito = (new RiparaSchema('mysql'))->ripara();

        $this->assertTrue($esito['eseguita']);
        $this->assertSame(['tags'], $esito['tabelle']);
        $this->assertSame(['soci.note'], $esito['colonne']);
        $this->assertTrue(Schema::connection('mysql')->hasColumn('soci', 'note'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('tags'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('tabella_di_altri'));

        // Seconda passata: niente da fare
        $di_nuovo = (new RiparaSchema('mysql'))->ripara();
        $this->assertSame([], $di_nuovo['tabelle']);
        $this->assertSame([], $di_nuovo['colonne']);

        // Anche dal comando
        Artisan::call('app:ripara-schema');
        $this->assertStringContainsString('Schema allineato', Artisan::output());
    }

    public function test_con_il_lock_preso_da_un_altro_processo_non_ripara_due_volte(): void
    {
        app(InstallerService::class)->installaSchema($this->cfg);

        $altro = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s', $this->cfg['host'], $this->cfg['port'], $this->cfg['database']),
            $this->cfg['username'],
            $this->cfg['password'],
        );
        $this->assertSame('1', (string) $altro->query("SELECT GET_LOCK('gestionale_ripara_schema', 0)")->fetchColumn());

        $esito = (new RiparaSchema('mysql'))->ripara();

        $this->assertFalse($esito['eseguita']);
        $this->assertSame('già in corso', $esito['motivo']);
    }
}
