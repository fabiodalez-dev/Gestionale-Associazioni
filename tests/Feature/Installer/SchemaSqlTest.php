<?php

namespace Tests\Feature\Installer;

use App\Services\Installer\InstallerService;
use App\Services\Installer\SchemaSql;
use RuntimeException;
use Tests\TestCase;

/**
 * Lo schema SQL dell'installer (database/installer/schema.sql): parsing del
 * dump e, soprattutto, allineamento con le migration del progetto.
 */
class SchemaSqlTest extends TestCase
{
    public function test_il_file_schema_esiste_ed_e_allineato_alle_migration(): void
    {
        $file = base_path('database/installer/schema.sql');
        $this->assertFileExists($file, 'Manca database/installer/schema.sql: esegui "php artisan installer:genera-schema".');

        $sql = (string) file_get_contents($file);

        $this->assertSame(
            SchemaSql::migrazioniDelProgetto(database_path('migrations')),
            SchemaSql::migrazioni($sql),
            'database/installer/schema.sql è indietro rispetto a database/migrations: rigeneralo con "php artisan installer:genera-schema".'
        );

        $tabelle = SchemaSql::tabelle($sql);
        foreach (['migrations', 'utenti', 'associazioni', 'roles', 'permissions', 'cache', 'jobs', 'sessions'] as $attesa) {
            $this->assertContains($attesa, $tabelle);
        }

        $istruzioni = SchemaSql::istruzioni($sql);
        $this->assertSame('SET NAMES utf8mb4;', $istruzioni[0]);
        $this->assertSame('SET FOREIGN_KEY_CHECKS = 0;', $istruzioni[1]);
        $this->assertSame('SET FOREIGN_KEY_CHECKS = 1;', end($istruzioni));
        // Niente dati applicativi nel dump: solo struttura + registro migration
        foreach ($istruzioni as $i) {
            if (preg_match('/^INSERT\s+INTO/i', $i)) {
                $this->assertMatchesRegularExpression('/^INSERT\s+INTO\s+`migrations`/', $i);
            }
        }
        // Dump deterministico: nessun contatore AUTO_INCREMENT del server
        $this->assertStringNotContainsString('AUTO_INCREMENT=', $sql);
    }

    public function test_istruzioni_divide_sul_punto_e_virgola_a_fine_riga_e_ignora_i_commenti(): void
    {
        $sql = "-- commento\n\nSET NAMES utf8mb4;\nCREATE TABLE `a` (\n  `x` varchar(10) COMMENT 'punto; e virgola',\n  `y` int\n);\n\nINSERT INTO `migrations` (`migration`, `batch`) VALUES\n('m1', 1),\n('m2', 1);\n";

        $istruzioni = SchemaSql::istruzioni($sql);

        $this->assertCount(3, $istruzioni);
        $this->assertSame('SET NAMES utf8mb4;', $istruzioni[0]);
        $this->assertStringStartsWith('CREATE TABLE `a`', $istruzioni[1]);
        $this->assertStringContainsString("COMMENT 'punto; e virgola'", $istruzioni[1]);
        $this->assertStringStartsWith('INSERT INTO `migrations`', $istruzioni[2]);
    }

    public function test_istruzioni_rifiuta_un_file_troncato(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incompleta');

        SchemaSql::istruzioni("SET NAMES utf8mb4;\nCREATE TABLE `a` (\n  `x` int\n");
    }

    public function test_tabelle_legge_nomi_con_e_senza_backtick(): void
    {
        $sql = "CREATE TABLE `zeta` (`id` int);\nCREATE TABLE IF NOT EXISTS alfa (`id` int);\ncreate table `Beta` (`id` int);\n";

        $this->assertSame(['alfa', 'beta', 'zeta'], SchemaSql::tabelle($sql));
    }

    public function test_tabelle_rifiuta_duplicati_e_dump_senza_tabelle(): void
    {
        try {
            SchemaSql::tabelle("CREATE TABLE `a` (`id` int);\nCREATE TABLE `a` (`id` int);");
            $this->fail('Attesa eccezione per duplicati');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('duplicate', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        SchemaSql::tabelle('SET NAMES utf8mb4;');
    }

    public function test_migrazioni_legge_le_righe_dell_insert(): void
    {
        $sql = "CREATE TABLE `migrations` (`id` int);\nINSERT INTO `migrations` (`migration`, `batch`) VALUES\n('2026_01_01_000001_b', 1),\n('2026_01_01_000000_a', 1);\n";

        $this->assertSame(['2026_01_01_000000_a', '2026_01_01_000001_b'], SchemaSql::migrazioni($sql));
    }

    public function test_migrazioni_rifiuta_un_dump_senza_registro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migrations');

        SchemaSql::migrazioni('CREATE TABLE `migrations` (`id` int);');
    }

    public function test_installa_schema_fallisce_chiaramente_se_il_file_manca(): void
    {
        config(['installer.schema_file' => sys_get_temp_dir().'/schema-inesistente-'.uniqid().'.sql']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('installer:genera-schema');

        app(InstallerService::class)->installaSchema([
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'x', 'username' => 'x', 'password' => '',
        ]);
    }
}
