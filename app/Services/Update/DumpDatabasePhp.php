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

        try {
            gzwrite($gz, "-- Backup di {$db} generato dall'updater di Gestione Associazioni il ".date('Y-m-d H:i:s')."\n");
            gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            $tabelle = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$pdo->quote($db)." AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
            if ($tabelle === []) {
                throw new RuntimeException('Nessuna tabella da salvare nel database '.$db);
            }

            foreach ($tabelle as $tabella) {
                $create = $pdo->query("SHOW CREATE TABLE `{$tabella}`")->fetch(PDO::FETCH_ASSOC);
                gzwrite($gz, "DROP TABLE IF EXISTS `{$tabella}`;\n".($create['Create Table'] ?? '').";\n\n");

                $stmt = $pdo->query("SELECT * FROM `{$tabella}`");
                $buffer = [];
                $colonne = null;
                while (($riga = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $colonne ??= '(`'.implode('`, `', array_keys($riga)).'`)';
                    $buffer[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $riga)).')';
                    if (count($buffer) >= self::RIGHE_PER_INSERT) {
                        gzwrite($gz, "INSERT INTO `{$tabella}` {$colonne} VALUES\n".implode(",\n", $buffer).";\n");
                        $buffer = [];
                    }
                }
                if ($buffer !== []) {
                    gzwrite($gz, "INSERT INTO `{$tabella}` {$colonne} VALUES\n".implode(",\n", $buffer).";\n");
                }
                gzwrite($gz, "\n");
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            gzclose($gz);
        }

        @chmod($file, 0600);

        return $file;
    }
}
