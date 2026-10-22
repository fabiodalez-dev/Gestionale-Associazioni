<?php

namespace App\Services\Sistema;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lettura e scrittura sicura del file .env per la pagina Impostazioni di sistema.
 *
 * - preserva commenti, ordine e variabili non gestite;
 * - quota i valori in modo che phpdotenv li rilegga identici (verificato rileggendo il file generato);
 * - salva un backup in storage/app/private/env-backups (fuori dalla document root) e sostituisce il file in modo atomico;
 * - svuota la cache della configurazione, così i nuovi valori valgono dalla richiesta successiva.
 */
class EnvEditor
{
    private const BACKUPS_TO_KEEP = 15;

    public function path(): string
    {
        return (string) config('installer.env_file', base_path('.env'));
    }

    public function backupDirectory(): string
    {
        return storage_path('app/private/env-backups');
    }

    public function isWritable(): bool
    {
        $path = $this->path();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /**
     * @return array<string, string|null>
     */
    public function read(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        return Dotenv::parse((string) file_get_contents($path));
    }

    public function get(string $key, string $default = ''): string
    {
        return (string) ($this->read()[$key] ?? $default);
    }

    /**
     * Applica le modifiche: stringa = imposta, null = rimuove la variabile.
     *
     * @param  array<string, string|null>  $changes
     */
    public function write(array $changes): void
    {
        foreach ($changes as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Nome variabile non valido: {$key}");
            }
            if ($value !== null && preg_match('/[\r\n\0]/', $value)) {
                throw new InvalidArgumentException("Il valore di {$key} non può contenere a capo.");
            }
        }
        if (! $this->isWritable()) {
            throw new RuntimeException('Il file .env non è scrivibile dal server web.');
        }

        $path = $this->path();
        $lines = is_file($path) ? preg_split('/\r\n|\n/', rtrim((string) file_get_contents($path), "\r\n")) : [];
        $pending = $changes;
        $output = [];

        foreach ($lines ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $pending)) {
                $value = $pending[$m[1]];
                unset($pending[$m[1]]);
                if ($value !== null) {
                    $output[] = $m[1].'='.$this->quote($value);
                }

                continue;
            }
            $output[] = $line;
        }

        $pending = array_filter($pending, fn (?string $v) => $v !== null);
        if ($pending !== []) {
            $output[] = '';
            $output[] = '# Impostazioni di sistema';
            foreach ($pending as $key => $value) {
                $output[] = $key.'='.$this->quote($value);
            }
        }

        $content = implode("\n", $output)."\n";
        $this->assertRoundTrip($content, $changes);

        $this->backup();

        $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Impossibile scrivere il file temporaneo per .env.');
        }
        @chmod($tmp, is_file($path) ? (fileperms($path) & 0777) : 0600);
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Impossibile sostituire il file .env.');
        }

        if (app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }
    }

    /**
     * Numero di backup disponibili.
     */
    public function backupCount(): int
    {
        return count(File::glob($this->backupDirectory().'/env-*.bak'));
    }

    /**
     * Quota il valore solo quando serve, in modo che phpdotenv lo rilegga identico.
     */
    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@,+-]+$/', $value)) {
            return $value;
        }
        if (! str_contains($value, "'")) {
            return "'".$value."'"; // apici singoli: nessuna interpolazione né escape
        }
        if (str_contains($value, '${')) {
            throw new InvalidArgumentException('Il valore non può contenere sia l\'apice singolo sia la sequenza ${.');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * Verifica che il contenuto generato venga riletto da phpdotenv con i valori attesi.
     *
     * @param  array<string, string|null>  $changes
     */
    private function assertRoundTrip(string $content, array $changes): void
    {
        try {
            $parsed = Dotenv::parse($content);
        } catch (Throwable $e) {
            throw new RuntimeException('Il file .env risultante non sarebbe valido: '.$e->getMessage(), 0, $e);
        }

        foreach ($changes as $key => $value) {
            $atteso = $value;
            $letto = $parsed[$key] ?? null;
            if ($atteso === null ? array_key_exists($key, $parsed) : $letto !== $atteso) {
                throw new RuntimeException("Il valore di {$key} non verrebbe salvato correttamente: modifica annullata.");
            }
        }
    }

    private function backup(): void
    {
        $path = $this->path();
        if (! is_file($path)) {
            return;
        }

        $dir = $this->backupDirectory();
        File::ensureDirectoryExists($dir, 0700);

        $target = $dir.'/env-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.bak';
        if (@copy($path, $target)) {
            @chmod($target, 0600);
        }

        $backups = File::glob($dir.'/env-*.bak');
        sort($backups);
        foreach (array_slice($backups, 0, max(0, count($backups) - self::BACKUPS_TO_KEEP)) as $old) {
            @unlink($old);
        }
    }
}
