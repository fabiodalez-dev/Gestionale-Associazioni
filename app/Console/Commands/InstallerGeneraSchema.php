<?php

namespace App\Console\Commands;

use App\Services\Installer\SchemaSql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

/**
 * Genera database/installer/schema.sql: lo schema del database VUOTO che
 * l'installer web inietta nel database configurato dall'utente (stesso
 * meccanismo dell'installer di Pinakes), al posto di eseguire le migration.
 *
 * Come funziona: crea un database temporaneo con le credenziali MySQL della
 * connessione configurata, ci esegue tutte le migration, legge ogni tabella
 * con SHOW CREATE TABLE più le righe della tabella `migrations`, scrive il
 * file e cancella il database temporaneo.
 *
 * Va rilanciato dopo OGNI nuova migration: il test
 * Tests\Feature\Installer\SchemaSqlTest fallisce se il file è indietro.
 */
class InstallerGeneraSchema extends Command
{
    protected $signature = 'installer:genera-schema
        {--connessione= : Connessione MySQL da cui prendere host e credenziali (predefinita: quella dell\'app)}
        {--database= : Nome del database temporaneo (deve finire con _schema_tmp)}
        {--output= : Percorso del file da scrivere (predefinito: database/installer/schema.sql)}
        {--mantieni : Non cancellare il database temporaneo alla fine}';

    protected $description = 'Genera lo schema SQL del database vuoto usato dall\'installer web';

    public function handle(): int
    {
        $sorgente = (string) ($this->option('connessione') ?: config('database.default'));
        $cfg = config("database.connections.{$sorgente}");

        if (! is_array($cfg) || ($cfg['driver'] ?? null) !== 'mysql') {
            $this->error("La connessione \"{$sorgente}\" non è MySQL: lo schema dell'installer è specifico per MySQL/MariaDB.");

            return self::FAILURE;
        }

        $nomeTmp = (string) ($this->option('database') ?: 'gestione_associazioni_schema_tmp');
        if (! preg_match('/^[A-Za-z0-9_]+_schema_tmp$/', $nomeTmp)) {
            $this->error('Il database temporaneo deve chiamarsi <nome>_schema_tmp (solo lettere, numeri e underscore): viene cancellato alla fine.');

            return self::FAILURE;
        }

        $output = (string) ($this->option('output') ?: config('installer.schema_file'));

        try {
            $pdo = $this->connessioneServer($cfg);
            $pdo->exec("DROP DATABASE IF EXISTS `{$nomeTmp}`");
            $pdo->exec("CREATE DATABASE `{$nomeTmp}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->line("Database temporaneo <info>{$nomeTmp}</info> creato.");

            // La connessione sorgente viene puntata sul database temporaneo
            // per tutta la generazione (anche le migration che nominano la
            // connessione, es. quelle dei pacchetti, devono finirci dentro)
            // e la cache va in memoria: con CACHE_STORE=database il seeder
            // dei permessi toccherebbe un DB che qui può non esistere.
            config([
                "database.connections.{$sorgente}.database" => $nomeTmp,
                "database.connections.{$sorgente}.url" => null,
                'database.default' => $sorgente,
                'cache.default' => 'array',
            ]);
            DB::purge($sorgente);
            DB::setDefaultConnection($sorgente);

            $this->line('Esecuzione delle migration…');
            $esito = Artisan::call('migrate', ['--database' => $sorgente, '--force' => true, '--no-interaction' => true]);
            if ($esito !== self::SUCCESS) {
                $this->error('Migration fallite:');
                $this->line(Artisan::output());

                return self::FAILURE;
            }

            $sql = $this->dump(DB::connection($sorgente)->getPdo(), $nomeTmp);

            File::ensureDirectoryExists(dirname($output));
            File::put($output, $sql);

            $tabelle = SchemaSql::tabelle($sql);
            $migrazioni = SchemaSql::migrazioni($sql);
            $attese = SchemaSql::migrazioniDelProgetto(database_path('migrations'));
            if ($migrazioni !== $attese) {
                $this->error('Le migration registrate nel dump non coincidono con database/migrations: controlla l\'output delle migration.');

                return self::FAILURE;
            }

            $this->info(sprintf(
                'Scritto %s: %d tabelle, %d migration registrate (%s).',
                str_replace(base_path().'/', '', $output),
                count($tabelle),
                count($migrazioni),
                $this->formatoDimensione(strlen($sql)),
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Generazione fallita: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $this->option('mantieni') && isset($pdo)) {
                DB::purge($sorgente);
                $pdo->exec("DROP DATABASE IF EXISTS `{$nomeTmp}`");
                $this->line("Database temporaneo {$nomeTmp} cancellato.");
            }
        }
    }

    /** @param  array<string, mixed>  $cfg */
    private function connessioneServer(array $cfg): PDO
    {
        $dsn = filled($cfg['unix_socket'] ?? null)
            ? 'mysql:unix_socket='.$cfg['unix_socket']
            : sprintf('mysql:host=%s;port=%s', $cfg['host'], $cfg['port']);

        return new PDO($dsn.';charset=utf8mb4', (string) $cfg['username'], (string) ($cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    /**
     * Dump deterministico: tabelle in ordine alfabetico, una istruzione per
     * riga logica terminata da ";", niente AUTO_INCREMENT né metadati del
     * server, così il file cambia solo quando cambia davvero lo schema.
     */
    private function dump(PDO $pdo, string $database): string
    {
        $stmt = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$pdo->quote($database)." AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        /** @var list<string> $tabelle */
        $tabelle = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $versione = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        $righe = [
            '-- Gestione Associazioni — schema del database vuoto per l\'installer web.',
            '-- Generato da `php artisan installer:genera-schema` (server '.$versione.').',
            '-- NON modificare a mano: rigenera il file dopo ogni nuova migration.',
            '-- Formato: ogni istruzione termina con ";" a fine riga; i commenti iniziano con "--".',
            '',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            '',
        ];

        foreach ($tabelle as $tabella) {
            $riga = $pdo->query("SHOW CREATE TABLE `{$tabella}`")->fetch(PDO::FETCH_ASSOC);
            $create = (string) ($riga['Create Table'] ?? '');
            $create = (string) preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $create);

            $righe[] = "DROP TABLE IF EXISTS `{$tabella}`;";
            $righe[] = $create.';';
            $righe[] = '';
        }

        $migrazioni = $pdo->query('SELECT migration, batch FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC);
        if ($migrazioni !== []) {
            $valori = array_map(
                fn (array $m) => sprintf("('%s', %d)", addslashes((string) $m['migration']), (int) $m['batch']),
                $migrazioni
            );
            $righe[] = '-- Migration già applicate dallo schema: `php artisan migrate` eseguirà solo quelle nuove.';
            $righe[] = 'INSERT INTO `migrations` (`migration`, `batch`) VALUES';
            $righe[] = implode(",\n", $valori).';';
            $righe[] = '';
        }

        $righe[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $righe[] = '';

        return implode("\n", $righe);
    }

    private function formatoDimensione(int $byte): string
    {
        return $byte >= 1024 * 1024
            ? sprintf('%.1f MB', $byte / 1024 / 1024)
            : sprintf('%d KB', (int) ceil($byte / 1024));
    }
}
