<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

/**
 * Dump MySQL in PHP puro (struttura + dati, gzip), come il backup
 * dell'updater di Pinakes: serve dove mysqldump non c'è (hosting condiviso)
 * e spatie/laravel-backup non può lavorare. Si ripristina con
 * `gunzip -c file.sql.gz | mysql db` o da phpMyAdmin.
 */
class DumpDatabasePhp
{
    private const RIGHE_PER_INSERT = 200;

    public function dump(string $file, ?string $connessione = null): string
    {
        $conn = DB::connection($connessione);
        if ($conn->getDriverName() !== 'mysql') {
            throw new RuntimeException('Il dump PHP supporta solo MySQL/MariaDB (connessione: '.$conn->getDriverName().').');
        }
        $pdo = $conn->getPdo();
        $db = (string) $conn->getDatabaseName();

        File::ensureDirectoryExists(dirname($file));
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Impossibile creare il file di backup '.$file);
        }

        // Le righe vengono lette una alla volta: con il buffer di PDO una
        // tabella grande (email_log, activity_log) finirebbe tutta in memoria
        $bufferizzato = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $scrivi = function (string $testo) use ($gz, $file): void {
            if (gzwrite($gz, $testo) !== strlen($testo)) {
                throw new RuntimeException('Scrittura del backup interrotta (spazio o quota esauriti): '.$file);
            }
        };

        try {
            $scrivi("-- Backup di {$db} generato dall'updater di Gestione Associazioni il ".date('Y-m-d H:i:s')."\n");
            $scrivi("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            $tabelle = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$pdo->quote($db)." AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
            if ($tabelle === []) {
                throw new RuntimeException('Nessuna tabella da salvare nel database '.$db);
            }

            foreach ($tabelle as $tabella) {
                $create = $pdo->query("SHOW CREATE TABLE `{$tabella}`")->fetch(PDO::FETCH_ASSOC);
                $scrivi("DROP TABLE IF EXISTS `{$tabella}`;\n".($create['Create Table'] ?? '').";\n\n");

                // Colonne binarie (blob, binary, varbinary): in esadecimale,
                // altrimenti il reimport con SET NAMES utf8mb4 le rifiuta
                $binarie = [];
                foreach ($pdo->query("SHOW COLUMNS FROM `{$tabella}`")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    if (preg_match('/blob|binary/i', (string) ($col['Type'] ?? ''))) {
                        $binarie[(string) $col['Field']] = true;
                    }
                }

                $stmt = $pdo->query("SELECT * FROM `{$tabella}`");
                $buffer = [];
                $colonne = null;
                while (($riga = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $colonne ??= '(`'.implode('`, `', array_keys($riga)).'`)';
                    $valori = [];
                    foreach ($riga as $campo => $v) {
                        $valori[] = match (true) {
                            $v === null => 'NULL',
                            isset($binarie[$campo]) => "X'".bin2hex((string) $v)."'",
                            default => $pdo->quote((string) $v),
                        };
                    }
                    $buffer[] = '('.implode(', ', $valori).')';
                    if (count($buffer) >= self::RIGHE_PER_INSERT) {
                        $scrivi("INSERT INTO `{$tabella}` {$colonne} VALUES\n".implode(",\n", $buffer).";\n");
                        $buffer = [];
                    }
                }
                $stmt->closeCursor();
                if ($buffer !== []) {
                    $scrivi("INSERT INTO `{$tabella}` {$colonne} VALUES\n".implode(",\n", $buffer).";\n");
                }
                $scrivi("\n");
            }

            $scrivi("SET FOREIGN_KEY_CHECKS = 1;\n");
            if (! gzclose($gz)) {
                throw new RuntimeException('Chiusura del file di backup fallita: '.$file);
            }
        } catch (\Throwable $e) {
            // Mai lasciare un backup parziale spacciato per buono
            @gzclose($gz);
            @unlink($file);
            throw $e;
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, (bool) $bufferizzato);
        }

        @chmod($file, 0600);

        return $file;
    }
}
