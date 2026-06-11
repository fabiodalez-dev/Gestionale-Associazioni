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
use PDO;
use PDOException;

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

        // Hardening permessi: file sensibili leggibili solo dal proprietario
        @chmod($lockFile, 0600);

        $envFile = config('installer.env_file');
        if (file_exists($envFile)) {
            @chmod($envFile, 0600);
        }
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
     * destinazione (per avvisare prima di migrare su un DB non vuoto).
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $cfg
     * @return array{ok: bool, error: ?string, tables: int}
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
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
            );
            $stmt->execute([$nomeDb]);
            $tables = (int) $stmt->fetchColumn();

            return ['ok' => true, 'error' => null, 'tables' => $tables];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'tables' => 0];
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
     * Applica a runtime la configurazione DB appena scritta e lancia
     * migrations + seeder dei ruoli.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $cfg
     */
    public function runMigrations(array $cfg): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $cfg['host'],
            'database.connections.mysql.port' => $cfg['port'],
            'database.connections.mysql.database' => $cfg['database'],
            'database.connections.mysql.username' => $cfg['username'],
            'database.connections.mysql.password' => (string) ($cfg['password'] ?? ''),
        ]);

        DB::purge('mysql');

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);
    }

    /**
     * Il database è raggiungibile e migrato?
     */
    public function databaseReady(): bool
    {
        try {
            return Schema::hasTable('utenti');
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
