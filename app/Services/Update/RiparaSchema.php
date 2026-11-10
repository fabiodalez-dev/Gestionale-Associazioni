<?php

namespace App\Services\Update;

use App\Services\Installer\SchemaSql;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Allinea il database a database/installer/schema.sql dopo un aggiornamento
 * (come Pinakes 0.9.1): una migration registrata come eseguita non prova che
 * tabelle e colonne esistano (aggiornamento interrotto, file caricati via FTP
 * senza passare dall'updater, migration fallita a metà su MySQL che non ha
 * DDL transazionali). Crea solo ciò che manca: mai DROP, mai modifiche di
 * tipo, indici e vincoli delle tabelle esistenti restano come sono.
 *
 * Solo MySQL/MariaDB: schema.sql è un dump MySQL.
 */
class RiparaSchema
{
    /** Marcatore della versione per cui il controllo è già stato fatto. */
    public const MARCATORE = 'app/schema-verificato';

    private const LOCK = 'gestionale_ripara_schema';

    public function __construct(private readonly ?string $connessione = null) {}

    /**
     * @return array{eseguita: bool, tabelle: list<string>, colonne: list<string>, motivo: ?string}
     */
    public function ripara(?string $schemaSql = null): array
    {
        $db = DB::connection($this->connessione);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ['eseguita' => false, 'tabelle' => [], 'colonne' => [], 'motivo' => 'database non MySQL'];
        }

        // Due richieste o un aggiornamento e una richiesta insieme: una sola ripara
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 0) AS ok', [self::LOCK])->ok !== 1) {
            return ['eseguita' => false, 'tabelle' => [], 'colonne' => [], 'motivo' => 'già in corso'];
        }

        try {
            return ['eseguita' => true, 'motivo' => null] + $this->allinea($db, $schemaSql ?? (string) file_get_contents(database_path('installer/schema.sql')));
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS ok', [self::LOCK]);
        }
    }

    /**
     * Al primo caricamento dopo un cambio di versione (file aggiornati via
     * FTP, oppure dall'updater della versione precedente): una sola volta per
     * versione, dopo l'invio della risposta.
     */
    public function seNuovaVersione(string $versione): void
    {
        $marcatore = storage_path(self::MARCATORE);
        if (is_file($marcatore) && trim((string) @file_get_contents($marcatore)) === $versione) {
            return;
        }

        app()->terminating(function () use ($marcatore, $versione): void {
            try {
                $esito = $this->ripara();
                if ($esito['eseguita'] || $esito['motivo'] === 'database non MySQL') {
                    @file_put_contents($marcatore, $versione.PHP_EOL);
                }
            } catch (\Throwable $e) {
                // Mai bloccare il sito: si riprova alla prossima richiesta
                Log::warning('RiparaSchema: controllo dopo il cambio di versione fallito', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * @return array{tabelle: list<string>, colonne: list<string>}
     */
    private function allinea(Connection $db, string $schemaSql): array
    {
        $esistenti = [];
        foreach ($db->select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $riga) {
            $esistenti[strtolower((string) $riga->t)] = true;
        }

        $tabelleCreate = [];
        $colonneAggiunte = [];
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (SchemaSql::definizioni($schemaSql) as $tabella => $definizione) {
                if (! isset($esistenti[$tabella])) {
                    $db->statement($definizione['create']);
                    $tabelleCreate[] = $tabella;

                    continue;
                }

                $presenti = [];
                foreach ($db->select('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabella]) as $riga) {
                    $presenti[strtolower((string) $riga->c)] = true;
                }
                foreach ($definizione['colonne'] as $colonna => $sql) {
                    if (! isset($presenti[strtolower($colonna)])) {
                        $db->statement("ALTER TABLE `{$tabella}` ADD COLUMN {$sql}");
                        $colonneAggiunte[] = "{$tabella}.{$colonna}";
                    }
                }
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        if ($tabelleCreate !== [] || $colonneAggiunte !== []) {
            Log::warning('RiparaSchema: create tabelle e colonne mancanti', ['tabelle' => $tabelleCreate, 'colonne' => $colonneAggiunte]);
        }

        return ['tabelle' => $tabelleCreate, 'colonne' => $colonneAggiunte];
    }
}
