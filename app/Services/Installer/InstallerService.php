<?php

namespace App\Services\Installer;

use App\Models\Associazione;
use App\Models\Utente;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;

class InstallerService
{
    public function isInstalled(): bool
    {
        return file_exists(config('installer.lock_file'));
    }

    public function markInstalled(): void
    {
        $lockFile = config('installer.lock_file');

        file_put_contents($lockFile, 'Installata il '.now()->format('Y-m-d H:i:s').PHP_EOL);
        @unlink((string) config('installer.in_progress_file'));

        // Hardening permessi: file sensibili leggibili solo dal proprietario
        @chmod($lockFile, 0600);

        $envFile = config('installer.env_file');
        if (file_exists($envFile)) {
            @chmod($envFile, 0600);
        }
    }

    /**
     * Segna che il wizard ha installato lo schema e sta proseguendo con gli
     * step successivi (admin, email): finché il marcatore è valido le rotte
     * /install restano aperte anche se nel frattempo esiste un super admin.
     */
    public function segnaWizardInCorso(): string
    {
        $token = bin2hex(random_bytes(32));
        $file = (string) config('installer.in_progress_file');

        file_put_contents($file, hash('sha256', $token).PHP_EOL);
        @chmod($file, 0600);

        return $token;
    }

    /**
     * Il wizard è in corso E la richiesta arriva dal browser che lo sta
     * facendo (token del cookie corrispondente all'hash nel marcatore).
     */
    public function wizardInCorso(?string $token): bool
    {
        $file = (string) config('installer.in_progress_file');
        if ($token === null || $token === '' || ! is_file($file)) {
            return false;
        }

        $minuti = (int) config('installer.in_progress_ttl_minutes', 120);
        if ((filemtime($file) ?: 0) < time() - $minuti * 60) {
            return false;
        }

        $atteso = trim((string) file_get_contents($file));

        return $atteso !== '' && hash_equals($atteso, hash('sha256', $token));
    }

    /**
     * Verifica dei requisiti di sistema.
     *
     * @return array{ok: bool, php: array{required: string, current: string, ok: bool}, extensions: array<string, bool>, writable: array<string, bool>, env_writable: bool}
     */
    public function requirements(): array
    {
        $req = config('installer.requirements');

        $phpOk = version_compare(PHP_VERSION, $req['php'], '>=');

        $extensions = [];
        foreach ($req['extensions'] as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        $writable = [];
        foreach ($req['writable'] as $path) {
            $writable[$path] = is_writable(base_path($path));
        }

        $envWritable = is_writable(config('installer.env_file'))
            || (! file_exists(config('installer.env_file')) && is_writable(base_path()));

        return [
            'ok' => $phpOk && ! in_array(false, $extensions, true)
                && ! in_array(false, $writable, true) && $envWritable,
            'php' => ['required' => $req['php'], 'current' => PHP_VERSION, 'ok' => $phpOk],
            'extensions' => $extensions,
            'writable' => $writable,
            'env_writable' => $envWritable,
        ];
    }

    /**
     * Tenta la connessione MySQL e, se richiesto, crea il database.
     * Restituisce anche quante tabelle contiene già il database di
     * destinazione (per avvisare prima di sovrascriverle) e se contiene
     * un'installazione completa (tabella utenti con un super admin): in
     * quel caso il wizard non deve mai cancellare nulla.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $cfg
     * @return array{ok: bool, error: ?string, tables: int, tabelle: list<string>, installata: bool, server: ?string}
     */
    public function testDatabase(array $cfg, bool $creaDatabase = false): array
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s', $cfg['host'], $cfg['port']),
                $cfg['username'],
                (string) ($cfg['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            $nomeDb = str_replace('`', '', $cfg['database']);

            if ($creaDatabase) {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$nomeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }

            $pdo->exec("USE `{$nomeDb}`");

            $stmt = $pdo->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME'
            );
            $stmt->execute([$nomeDb]);
            /** @var list<string> $nomiTabelle */
            $nomiTabelle = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $versione = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            if ($errore = $this->versioneServerTroppoVecchia($versione)) {
                return ['ok' => false, 'error' => $errore, 'tables' => 0, 'tabelle' => [], 'installata' => false, 'server' => $versione];
            }

            return [
                'ok' => true,
                'error' => null,
                'tables' => count($nomiTabelle),
                'tabelle' => $nomiTabelle,
                'installata' => $this->contieneInstallazione($pdo, $nomeDb),
                'server' => $versione,
            ];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'tables' => 0, 'tabelle' => [], 'installata' => false, 'server' => null];
        }
    }

    /**
     * Messaggio d'errore se il server è più vecchio del minimo supportato
     * dallo schema (colonne json, utf8mb4_unicode_ci), altrimenti null.
     */
    public function versioneServerTroppoVecchia(string $versione): ?string
    {
        $mariadb = stripos($versione, 'mariadb') !== false;
        $minima = (string) config($mariadb ? 'installer.min_mariadb' : 'installer.min_mysql');
        preg_match('/\\d+(?:\\.\\d+){1,2}/', $versione, $m);
        $numero = $m[0] ?? '0';

        if (version_compare($numero, $minima, '>=')) {
            return null;
        }

        return sprintf('Server %s troppo vecchio (%s): serve almeno %s %s.', $mariadb ? 'MariaDB' : 'MySQL', $versione, $mariadb ? 'MariaDB' : 'MySQL', $minima);
    }

    /**
     * Tabelle dell'applicazione già presenti nel database di destinazione:
     * sono quelle che l'installazione cancellerebbe e ricreerebbe vuote.
     *
     * @param  list<string>  $esistenti
     * @return list<string>
     */
    public function tabelleDaSovrascrivere(array $esistenti): array
    {
        $file = (string) config('installer.schema_file');
        $attese = is_file($file) ? SchemaSql::tabelle((string) file_get_contents($file)) : [];

        return array_values(array_intersect($attese, array_map('strtolower', $esistenti)));
    }

    /**
     * Il database contiene già un'installazione dell'app (un super admin)?
     * Letto con la connessione dell'installer, non con quella del .env.
     */
    private function contieneInstallazione(PDO $pdo, string $nomeDb): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$nomeDb, 'utenti']);
        if ((int) $stmt->fetchColumn() === 0) {
            return false;
        }

        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM `{$nomeDb}`.`utenti` WHERE `ruolo` = 'super_admin'")->fetchColumn() > 0;
        } catch (PDOException) {
            // Tabella `utenti` di un'altra applicazione, senza colonna ruolo
            return false;
        }
    }

    /**
     * Dati dimostrativi (solo contenuti, nessun account utente).
     */
    public function seedDemo(): void
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    }

    /**
     * Scrive (o aggiorna) coppie KEY=VALUE nel file .env.
     *
     * @param  array<string, string>  $valori
     */
    public function writeEnv(array $valori): void
    {
        $envFile = config('installer.env_file');

        $contenuto = file_exists($envFile)
            ? (string) file_get_contents($envFile)
            : (string) file_get_contents(base_path('.env.example'));

        foreach ($valori as $chiave => $valore) {
            $valore = $this->quoteEnvValue($valore);

            if (preg_match("/^{$chiave}=.*$/m", $contenuto)) {
                $contenuto = (string) preg_replace("/^{$chiave}=.*$/m", "{$chiave}={$valore}", $contenuto);
            } else {
                $contenuto .= PHP_EOL."{$chiave}={$valore}";
            }
        }

        file_put_contents($envFile, $contenuto);
    }

    private function quoteEnvValue(string $valore): string
    {
        if ($valore === '' || preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $valore)) {
            return $valore;
        }

        return '"'.str_replace('"', '\\"', $valore).'"';
    }

    /**
     * Applica a runtime la configurazione DB appena scritta nel .env.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $cfg
     */
    public function configuraConnessione(array $cfg): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.unix_socket' => '',
            'database.connections.mysql.host' => $cfg['host'],
            'database.connections.mysql.port' => $cfg['port'],
            'database.connections.mysql.database' => $cfg['database'],
            'database.connections.mysql.username' => $cfg['username'],
            'database.connections.mysql.password' => (string) ($cfg['password'] ?? ''),
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
    }

    /**
     * Inietta lo schema del database vuoto (database/installer/schema.sql)
     * nel database configurato, come fa l'installer di Pinakes: niente
     * migration a runtime, un unico dump controllato eseguito istruzione per
     * istruzione via PDO (funziona anche dove exec() è disabilitato).
     * Il dump registra anche le migration già applicate, così gli
     * aggiornamenti futuri eseguono solo quelle nuove. Alla fine verifica che
     * ogni tabella dichiarata esista e allinea ruoli e permessi.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $cfg
     * @return array{tabelle: int, migrazioni: int}
     *
     * @throws RuntimeException se il file manca, è incompleto o l'import non produce tutte le tabelle
     */
    public function installaSchema(array $cfg): array
    {
        $file = (string) config('installer.schema_file');
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('File dello schema non trovato: '.str_replace(base_path().'/', '', $file).'. Genera il file con "php artisan installer:genera-schema".');
        }

        $sql = (string) file_get_contents($file);
        $istruzioni = SchemaSql::istruzioni($sql);
        $attese = SchemaSql::tabelle($sql);
        $migrazioni = SchemaSql::migrazioni($sql);

        $this->configuraConnessione($cfg);
        $connessione = DB::connection('mysql');

        foreach ($istruzioni as $i => $istruzione) {
            try {
                $connessione->unprepared($istruzione);
            } catch (\Throwable $e) {
                // Il QueryException di Laravel include l'intera istruzione nel
                // messaggio: all'utente basta l'errore del server, accorciato
                $messaggio = $e->getPrevious()?->getMessage() ?? $e->getMessage();

                throw new RuntimeException(sprintf(
                    'Errore nell\'istruzione %d di %d dello schema (%s): %s',
                    $i + 1,
                    count($istruzioni),
                    Str::limit(preg_replace('/\s+/', ' ', $istruzione) ?? '', 80),
                    Str::limit($messaggio, 300),
                ), 0, $e);
            }
        }

        // Connessione nuova: l'elenco tabelle deve vedere quelle appena create
        DB::purge('mysql');
        $presenti = array_map('strtolower', Schema::connection('mysql')->getTableListing(schemaQualified: false));
        $mancanti = array_values(array_diff($attese, $presenti));
        if ($mancanti !== []) {
            throw new RuntimeException('Schema importato in modo incompleto. Tabelle mancanti: '.implode(', ', $mancanti));
        }

        Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);

        return ['tabelle' => count($attese), 'migrazioni' => count($migrazioni)];
    }

    /**
     * Il database è raggiungibile e migrato?
     */
    public function databaseReady(): bool
    {
        try {
            // `utenti` esiste e il registro delle migration è popolato: l'INSERT
            // in `migrations` è l'ultima istruzione dello schema, quindi un
            // import interrotto a metà non passa per "pronto"
            return Schema::hasTable('utenti')
                && Schema::hasTable('migrations')
                && DB::table('migrations')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function adminExists(): bool
    {
        return $this->databaseReady()
            && Utente::query()->where('ruolo', 'super_admin')->exists();
    }

    /**
     * Crea l'associazione iniziale e il super admin.
     *
     * @param  array{associazione_nome: string, associazione_email: string, nome: string, cognome: string, email: string, password: string}  $dati
     */
    public function createAdmin(array $dati): Utente
    {
        return DB::transaction(function () use ($dati) {
            $associazione = Associazione::create([
                'nome' => $dati['associazione_nome'],
                'email' => $dati['associazione_email'],
                'attiva' => true,
            ]);

            $admin = Utente::create([
                'nome' => $dati['nome'],
                'cognome' => $dati['cognome'],
                'email' => $dati['email'],
                'password_hash' => Hash::make($dati['password']),
                'ruolo' => 'super_admin',
                'associazione_id' => $associazione->id,
                'attivo' => true,
            ]);

            $admin->assignRole('super_admin');

            return $admin;
        });
    }
}
