<?php

namespace App\Services\Installer;

use RuntimeException;

/**
 * Lettura del file database/installer/schema.sql (lo schema del database
 * vuoto che l'installer inietta nel database configurato dall'utente).
 *
 * Il file è generato da `php artisan installer:genera-schema` in un formato
 * controllato: ogni istruzione termina con ";" a fine riga, i commenti sono
 * righe che iniziano con "--". Niente DELIMITER, trigger o procedure.
 * Questa classe non tocca il database: solo parsing e controlli, così è
 * verificabile nei test senza MySQL.
 */
final class SchemaSql
{
    /**
     * Spezza il dump nelle singole istruzioni SQL da eseguire, nell'ordine.
     *
     * @return list<string>
     */
    public static function istruzioni(string $sql): array
    {
        $istruzioni = [];
        $buffer = '';

        foreach (preg_split('/\r\n|\r|\n/', $sql) ?: [] as $riga) {
            $pulita = trim($riga);

            if ($pulita === '' && $buffer === '') {
                continue;
            }
            if (str_starts_with($pulita, '--') && $buffer === '') {
                continue;
            }

            $buffer .= ($buffer === '' ? '' : "\n").$riga;

            if (str_ends_with($pulita, ';')) {
                $istruzioni[] = trim($buffer);
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            throw new RuntimeException('schema.sql termina con un\'istruzione incompleta (manca il ";" finale).');
        }

        return $istruzioni;
    }

    /**
     * Nomi delle tabelle dichiarate con CREATE TABLE, ordinati.
     * Rifiuta parsing parziali e duplicati: un CREATE TABLE scritto in modo
     * inatteso non deve indebolire la verifica post-installazione.
     *
     * @return list<string>
     */
    public static function tabelle(string $sql): array
    {
        preg_match_all('/^\s*CREATE\s+TABLE\b/im', $sql, $dichiarazioni);
        preg_match_all(
            '/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_$]*))/im',
            $sql,
            $trovate,
            PREG_SET_ORDER
        );

        if ($dichiarazioni[0] === []) {
            throw new RuntimeException('schema.sql non contiene dichiarazioni CREATE TABLE.');
        }
        if (count($trovate) !== count($dichiarazioni[0])) {
            throw new RuntimeException(sprintf(
                'Impossibile ricavare tutte le tabelle da schema.sql: %d nomi su %d CREATE TABLE.',
                count($trovate),
                count($dichiarazioni[0])
            ));
        }

        $tabelle = [];
        foreach ($trovate as $t) {
            // Il gruppo 2 (nome senza backtick) è presente solo quando il gruppo 1 non ha fatto match
            $tabelle[] = strtolower($t[2] ?? $t[1]);
        }
        if (count(array_unique($tabelle)) !== count($tabelle)) {
            throw new RuntimeException('schema.sql contiene dichiarazioni CREATE TABLE duplicate.');
        }

        sort($tabelle);

        return $tabelle;
    }

    /**
     * Nomi delle migration registrate nel dump (righe INSERT nella tabella
     * `migrations`), ordinati. Servono a far sì che `php artisan migrate`
     * dopo l'installazione applichi solo le migration nuove.
     *
     * @return list<string>
     */
    public static function migrazioni(string $sql): array
    {
        $migrazioni = [];
        $dentroInsert = false;

        foreach (self::istruzioni($sql) as $istruzione) {
            if (! preg_match('/^INSERT\s+INTO\s+`?migrations`?\s*\(/i', $istruzione)) {
                continue;
            }
            $dentroInsert = true;
            preg_match_all("/\\('((?:[^'\\\\]|\\\\.)+)',\\s*\\d+\\)/", $istruzione, $righe);
            foreach ($righe[1] as $nome) {
                $migrazioni[] = $nome;
            }
        }

        if (! $dentroInsert) {
            throw new RuntimeException('schema.sql non registra nessuna migration nella tabella `migrations`.');
        }

        sort($migrazioni);

        return $migrazioni;
    }

    /**
     * Nomi delle migration presenti nella cartella del progetto, ordinati
     * (senza estensione), per il confronto con quelle registrate nel dump.
     *
     * @return list<string>
     */
    public static function migrazioniDelProgetto(string $cartella): array
    {
        $file = glob(rtrim($cartella, '/').'/*.php') ?: [];
        $nomi = array_map(fn (string $f) => basename($f, '.php'), $file);
        sort($nomi);

        return $nomi;
    }
}
