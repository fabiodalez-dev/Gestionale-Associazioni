<?php

namespace Tests\Feature\Admin;

use App\Services\Installer\InstallerService;
use App\Services\Installer\SchemaSql;
use App\Services\Update\DumpDatabasePhp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * Dump in PHP puro del database (fallback dell'updater quando mysqldump non
 * c'è): si attiva con INSTALLER_TEST_MYSQL_DSN come il test dell'installer.
 */
class DumpDatabasePhpMysqlTest extends TestCase
{
    /** @var array{host: string, port: int, database: string, username: string, password: string} */
    private array $cfg;

    private PDO $server;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = (string) env('INSTALLER_TEST_MYSQL_DSN', '');
        if ($dsn === '') {
            $this->markTestSkipped('Imposta INSTALLER_TEST_MYSQL_DSN per provare il dump PHP su MySQL.');
        }
        $p = parse_url($dsn) ?: [];
        $database = ltrim((string) ($p['path'] ?? ''), '/');
        if (! str_contains($database, 'test')) {
            $this->fail('Il database di prova deve contenere "test" nel nome.');
        }
        $this->cfg = [
            'host' => $p['host'] ?? '127.0.0.1', 'port' => (int) ($p['port'] ?? 3306), 'database' => $database,
            'username' => urldecode($p['user'] ?? 'root'), 'password' => urldecode($p['pass'] ?? ''),
        ];
        $this->server = new PDO(sprintf('mysql:host=%s;port=%d', $this->cfg['host'], $this->cfg['port']), $this->cfg['username'], $this->cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec("DROP DATABASE IF EXISTS `{$database}`");
        $this->server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
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

    public function test_il_dump_contiene_struttura_e_dati_ed_e_reimportabile(): void
    {
        $installer = app(InstallerService::class);
        $installer->installaSchema($this->cfg);
        $installer->createAdmin([
            'associazione_nome' => 'APS Dump', 'associazione_email' => 'dump@prova.test',
            'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@dump.test', 'password' => 'SuperSegreta123',
        ]);

        $file = sys_get_temp_dir().'/dump_'.uniqid().'.sql.gz';
        try {
            $this->assertSame($file, (new DumpDatabasePhp)->dump($file));
            $sql = (string) gzdecode((string) file_get_contents($file));

            $this->assertStringContainsString('CREATE TABLE `utenti`', $sql);
            $this->assertStringContainsString('INSERT INTO `utenti`', $sql);
            $this->assertStringContainsString('mario@dump.test', $sql);
            $this->assertStringContainsString('INSERT INTO `roles`', $sql);
            $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);

            // Reimportato in un database vuoto ricrea tabelle e righe
            $db2 = $this->cfg['database'].'_dump';
            $this->server->exec("DROP DATABASE IF EXISTS `{$db2}`");
            $this->server->exec("CREATE DATABASE `{$db2}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            try {
                $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $this->cfg['host'], $this->cfg['port'], $db2), $this->cfg['username'], $this->cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                foreach (SchemaSql::istruzioni($sql) as $istruzione) {
                    $pdo->exec($istruzione);
                }
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM utenti')->fetchColumn());
                $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
            } finally {
                $this->server->exec("DROP DATABASE IF EXISTS `{$db2}`");
            }
        } finally {
            File::delete($file);
        }
    }

    public function test_rifiuta_connessioni_non_mysql(): void
    {
        $this->expectException(RuntimeException::class);
        (new DumpDatabasePhp)->dump(sys_get_temp_dir().'/mai.sql.gz', 'sqlite');
    }
}
