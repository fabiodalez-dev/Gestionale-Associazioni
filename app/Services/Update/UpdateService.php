<?php

namespace App\Services\Update;

use App\Models\UpdateLog;
use App\Models\Utente;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Aggiornamento in-app dell'applicazione (pagina /aggiornamenti, super_admin).
 *
 * Flusso performUpdate():
 *  1. lock distribuito (Cache::lock) → niente update concorrenti;
 *  2. backup del database (spatie/laravel-backup);
 *  3. download del pacchetto della release (asset <repo>-<tag>.zip, con
 *     vendor e asset frontend; in mancanza lo zipball dei sorgenti) +
 *     verifica SHA256 dell'asset .sha256 + anti zip-slip;
 *  4. maintenance mode (artisan down), sovrascrittura file applicativi
 *     (preservando .env/storage/VERSION), aggiornamento del file VERSION;
 *  5. php artisan migrate --force, allineamento ruoli/permessi, pulizia cache, queue:restart, artisan up;
 *  6. su errore: ripristino dei file dal backup pre-update e artisan up
 *     (rollback), stato 'rollback'/'fallito' nel log.
 *
 * Modellato sull'updater di Pinakes/biblioteca, adattato a Laravel.
 */
class UpdateService
{
    private const LOCK_KEY = 'app.update';

    /** Cartelle salvate prima della sovrascrittura e ripristinate dal rollback. */
    private const DIR_BACKUP = ['app', 'bootstrap', 'config', 'database', 'lang', 'routes', 'resources', 'vendor', 'bin', 'public/assets', 'public/build'];

    public function versioneCorrente(): string
    {
        return (string) config('update.version', '0.1.0');
    }

    public function selfUpdateAbilitato(): bool
    {
        return (bool) config('update.self_update_enabled', true);
    }

    /**
     * Controlla la presenza di una nuova release su GitHub.
     *
     * @return array{disponibile: bool, corrente: string, ultima: ?string, note: ?string, url: ?string, errore: ?string}
     */
    public function controllaAggiornamenti(): array
    {
        $corrente = $this->versioneCorrente();
        $owner = config('update.github.owner');
        $repo = config('update.github.repo');

        $esitoBase = [
            'disponibile' => false,
            'corrente' => $corrente,
            'ultima' => null,
            'note' => null,
            'url' => null,
            'errore' => null,
        ];

        try {
            $release = $this->ultimaRelease($owner, $repo);

            if ($release === null) {
                return ['errore' => 'Nessuna release trovata.'] + $esitoBase;
            }

            $ultima = ltrim((string) ($release['tag_name'] ?? ''), 'v');

            return [
                'disponibile' => $ultima !== '' && version_compare($ultima, $corrente, '>'),
                'corrente' => $corrente,
                'ultima' => $ultima,
                'note' => $release['body'] ?? null,
                'url' => $release['html_url'] ?? null,
                'errore' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('UpdateService: controllo aggiornamenti fallito', ['error' => $e->getMessage()]);

            return ['errore' => 'Impossibile contattare GitHub: '.$e->getMessage()] + $esitoBase;
        }
    }

    /**
     * Applica l'aggiornamento alla versione indicata. Idempotente sul lock.
     *
     * @return array{success: bool, error: ?string, log_id: ?string}
     */
    public function applicaAggiornamento(string $versione, ?Utente $esecutore = null): array
    {
        if (! $this->selfUpdateAbilitato()) {
            return ['success' => false, 'error' => 'Auto-aggiornamento disabilitato su questa installazione.', 'log_id' => null];
        }

        $lock = Cache::lock(self::LOCK_KEY, 1800);

        if (! $lock->get()) {
            return ['success' => false, 'error' => 'Un altro aggiornamento è già in corso.', 'log_id' => null];
        }

        $log = UpdateLog::create([
            'from_version' => $this->versioneCorrente(),
            'to_version' => $versione,
            'stato' => UpdateLog::STATO_AVVIATO,
            'eseguito_da' => $esecutore?->id,
        ]);

        $backupFiles = null;

        try {
            // 1. Backup database (spatie/laravel-backup, solo DB)
            $backupPath = $this->backupDatabase();
            $log->update(['backup_path' => $backupPath]);

            // 2. Download + verifica + estrazione
            $sorgente = $this->scaricaEdEstrai($versione);

            // 3. Maintenance + backup file applicativi + sovrascrittura
            Artisan::call('down', ['--render' => 'errors::503']);
            $backupFiles = $this->backupFileApplicativi();
            $this->sovrascriviFile($sorgente);
            File::put(base_path('VERSION'), $versione."\n");

            // 4. Migrazioni + cache + queue
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('app:aggiorna-permessi');
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            Artisan::call('view:clear');
            Artisan::call('queue:restart');

            Artisan::call('up');

            $log->update([
                'stato' => UpdateLog::STATO_COMPLETATO,
                'completato_at' => now(),
            ]);

            $this->pulisci($sorgente, $backupFiles);

            return ['success' => true, 'error' => null, 'log_id' => $log->id];
        } catch (\Throwable $e) {
            Log::error('UpdateService: aggiornamento fallito', [
                'versione' => $versione,
                'error' => $e->getMessage(),
            ]);

            // Rollback dei file se la sovrascrittura era già iniziata
            $stato = UpdateLog::STATO_FALLITO;
            if ($backupFiles !== null) {
                try {
                    $this->ripristinaFile($backupFiles);
                    $stato = UpdateLog::STATO_ROLLBACK;
                } catch (\Throwable $rollbackError) {
                    Log::critical('UpdateService: rollback fallito', ['error' => $rollbackError->getMessage()]);
                }
            }

            Artisan::call('up');

            $log->update([
                'stato' => $stato,
                'messaggio_errore' => $e->getMessage(),
                'completato_at' => now(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'log_id' => $log->id];
        } finally {
            $lock->release();
        }
    }

    // ------------------------------------------------------------------
    // Passi interni
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function ultimaRelease(string $owner, string $repo): ?array
    {
        $client = Http::withHeaders($this->headerGitHub())->timeout(15);

        if (config('update.channel') === 'rc') {
            $resp = $client->get("https://api.github.com/repos/{$owner}/{$repo}/releases", ['per_page' => 10]);
            abort_unless($resp->successful(), 502, 'GitHub API: '.$resp->status());
            $releases = collect($resp->json())->reject(fn ($r) => ($r['draft'] ?? false));

            return $releases->first();
        }

        $resp = $client->get("https://api.github.com/repos/{$owner}/{$repo}/releases/latest");

        if ($resp->status() === 404) {
            return null;
        }
        abort_unless($resp->successful(), 502, 'GitHub API: '.$resp->status());

        return $resp->json();
    }

    /** @return array<string, string> */
    private function headerGitHub(): array
    {
        $h = [
            'User-Agent' => 'GestioneAssociazioni-Updater',
            'Accept' => 'application/vnd.github+json',
        ];
        $token = config('update.github.token');
        if (filled($token)) {
            $h['Authorization'] = 'Bearer '.$token;
        }

        return $h;
    }

    private function backupDatabase(): ?string
    {
        try {
            Artisan::call('backup:run', ['--only-db' => true]);

            return 'spatie-backup';
        } catch (\Throwable $e) {
            // Il backup non deve bloccare l'update se non configurato, ma logghiamo
            Log::warning('UpdateService: backup DB non riuscito', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Scarica lo zip della release, verifica lo SHA256 se l'asset è presente,
     * controlla l'assenza di path traversal ed estrae. Ritorna il path della
     * cartella sorgente (radice del progetto dentro lo zip).
     */
    private function scaricaEdEstrai(string $versione): string
    {
        $owner = config('update.github.owner');
        $repo = config('update.github.repo');
        $tag = str_starts_with($versione, 'v') ? $versione : 'v'.$versione;

        $tmp = config('update.temp_path');
        File::ensureDirectoryExists($tmp);
        $zipPath = $tmp.'/release_'.$versione.'.zip';

        // Pacchetto completo pubblicato dalla release (vendor e asset
        // inclusi, lo stesso che si installa via FTP); se la release non lo
        // ha, zipball dei sorgenti (richiede composer e npm sul server)
        $archivio = $this->archivioRelease($tag);
        $resp = Http::withHeaders($this->headerGitHub())
            ->timeout((int) config('update.download_timeout', 300))
            ->get($archivio['url']);
        abort_unless($resp->successful(), 502, 'Download release fallito: '.$resp->status());
        File::put($zipPath, $resp->body());

        // Lo SHA256 pubblicato è quello del pacchetto: lo zipball dei
        // sorgenti non può corrispondere, per quello basta HTTPS
        if ($archivio['asset']) {
            $this->verificaChecksum($owner, $repo, $tag, $zipPath);
        }

        $estrazione = $tmp.'/extract_'.$versione;
        File::deleteDirectory($estrazione);
        File::ensureDirectoryExists($estrazione);

        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath) === true, 500, 'Archivio zip non valido.');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_starts_with($entry, '\\')) {
                $zip->close();
                abort(500, 'Percorso non valido nell\'archivio: '.$entry);
            }
        }

        $zip->extractTo($estrazione);
        $zip->close();
        File::delete($zipPath);

        // Una sola cartella radice: gestionale-associazioni/ nel pacchetto,
        // <owner>-<repo>-<sha>/ nello zipball GitHub
        $dirs = File::directories($estrazione);
        abort_unless(count($dirs) === 1, 500, 'Struttura zip release inattesa.');

        return $dirs[0];
    }

    /**
     * URL da scaricare per una tag: l'asset <repo>-<tag>.zip della release se
     * pubblicato (pacchetto completo di bin/build-release.sh), altrimenti lo
     * zipball dei sorgenti.
     *
     * @return array{url: string, asset: bool}
     */
    public function archivioRelease(string $tag): array
    {
        $owner = config('update.github.owner');
        $repo = config('update.github.repo');
        $nomeAsset = "{$repo}-{$tag}.zip";

        try {
            $resp = Http::withHeaders($this->headerGitHub())->timeout(15)
                ->get("https://api.github.com/repos/{$owner}/{$repo}/releases/tags/{$tag}");

            if ($resp->successful()) {
                foreach ((array) $resp->json('assets', []) as $asset) {
                    if (($asset['name'] ?? null) === $nomeAsset && filled($asset['browser_download_url'] ?? null)) {
                        return ['url' => (string) $asset['browser_download_url'], 'asset' => true];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('UpdateService: elenco asset della release non letto', ['error' => $e->getMessage()]);
        }

        return ['url' => "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$tag}", 'asset' => false];
    }

    private function verificaChecksum(string $owner, string $repo, string $tag, string $zipPath): void
    {
        // Solo l'errore di rete sul download del checksum è tollerato: un
        // checksum presente ma discordante deve bloccare l'aggiornamento
        try {
            $assetUrl = "https://github.com/{$owner}/{$repo}/releases/download/{$tag}/{$repo}-{$tag}.zip.sha256";
            $resp = Http::timeout(15)->get($assetUrl);
        } catch (\Throwable $e) {
            Log::info('UpdateService: checksum non verificato', ['error' => $e->getMessage()]);

            return;
        }

        if (! $resp->successful()) {
            return; // nessun checksum pubblicato: si procede (HTTPS)
        }

        $atteso = trim(explode(' ', trim($resp->body()))[0]);
        $reale = hash_file('sha256', $zipPath);

        abort_if(
            $atteso !== '' && ! hash_equals($atteso, (string) $reale),
            500,
            'Checksum SHA256 della release non corrispondente: download corrotto o manomesso.'
        );
    }

    private function sovrascriviFile(string $sorgente): void
    {
        $preserve = (array) config('update.preserve_paths', []);
        $skip = (array) config('update.skip_paths', []);
        $root = base_path();

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sorgente, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $rel = ltrim(str_replace($sorgente, '', $item->getPathname()), '/\\');
            $rel = str_replace('\\', '/', $rel);

            if ($this->matchPrefix($rel, $skip) || $this->matchPrefix($rel, $preserve)) {
                continue;
            }

            $dest = $root.'/'.$rel;

            // Difesa anti path traversal: il dest deve restare sotto root
            $realDestDir = realpath(dirname($dest)) ?: dirname($dest);
            if (! str_starts_with($realDestDir, realpath($root) ?: $root)) {
                continue;
            }

            if ($item->isDir()) {
                File::ensureDirectoryExists($dest);
            } else {
                File::ensureDirectoryExists(dirname($dest));
                File::copy($item->getPathname(), $dest);
            }
        }
    }

    /** @return string cartella di backup dei file applicativi */
    private function backupFileApplicativi(): string
    {
        $tmp = config('update.temp_path');
        $backupDir = $tmp.'/filebackup_'.now()->format('YmdHis');
        File::ensureDirectoryExists($backupDir);

        // Backup delle directory di codice che l'update può sovrascrivere
        // (il pacchetto della release contiene anche vendor e public/build)
        foreach (self::DIR_BACKUP as $dir) {
            $src = base_path($dir);
            if (File::isDirectory($src)) {
                File::copyDirectory($src, $backupDir.'/'.$dir);
            }
        }
        if (File::exists(base_path('composer.json'))) {
            File::copy(base_path('composer.json'), $backupDir.'/composer.json');
        }

        return $backupDir;
    }

    private function ripristinaFile(string $backupDir): void
    {
        // Sostituzione completa: le dipendenze o le classi arrivate con il
        // pacchetto nuovo non devono restare accanto al codice ripristinato
        foreach (self::DIR_BACKUP as $dir) {
            if (! File::isDirectory($backupDir.'/'.$dir)) {
                continue;
            }
            File::deleteDirectory(base_path($dir));
            File::copyDirectory($backupDir.'/'.$dir, base_path($dir));
        }
        if (File::exists($backupDir.'/composer.json')) {
            File::copy($backupDir.'/composer.json', base_path('composer.json'));
        }
    }

    private function pulisci(string $sorgente, ?string $backupFiles): void
    {
        File::deleteDirectory(dirname($sorgente));
        if ($backupFiles !== null) {
            File::deleteDirectory($backupFiles);
        }
    }

    /**
     * @param  list<string>  $prefissi
     */
    private function matchPrefix(string $rel, array $prefissi): bool
    {
        foreach ($prefissi as $p) {
            $p = trim($p, '/');
            if ($rel === $p || str_starts_with($rel, $p.'/')) {
                return true;
            }
        }

        return false;
    }
}
