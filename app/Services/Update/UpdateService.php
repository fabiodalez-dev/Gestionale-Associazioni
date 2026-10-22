<?php

namespace App\Services\Update;

use App\Models\UpdateLog;
use App\Models\Utente;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Aggiornamento in-app (pagina /aggiornamenti e comando app:aggiorna),
 * modellato sull'updater di Pinakes e adattato a Laravel.
 *
 * Flusso di applicaAggiornamento():
 *  0. lock esclusivo su file (flock) → mai due update insieme; preflight
 *     (PHP, zip, cartelle scrivibili, spazio, prova di scrittura);
 *  1. backup del database: spatie/laravel-backup, altrimenti dump in PHP puro;
 *  2. pacchetto: asset <repo>-<tag>.zip della release GitHub scaricato su
 *     file e verificato con il digest sha256 dell'API (fail-closed), oppure
 *     uno zip locale caricato dall'utente o indicato da CLI;
 *  3. estrazione con controllo anti zip-slip, cartella sorgente riconosciuta
 *     (artisan, VERSION, vendor/autoload.php, public/index.php), versione
 *     del pacchetto uguale a quella richiesta;
 *  4. verifica a secco che ogni destinazione sia scrivibile e che ci sia
 *     spazio per backup e copia;
 *  5. manutenzione, backup dei file che verranno sovrascritti, copia del
 *     pacchetto (preservando .env, storage, upload), rimozione dei file
 *     orfani nelle cartelle di codice;
 *  6. php artisan migrate --force, allineamento permessi, pulizia cache,
 *     riavvio coda, collegamento storage; fine manutenzione;
 *  7. su errore dopo la copia: ripristino dei file dal backup e stato
 *     'rollback'; altrimenti 'fallito'. Un errore fatale toglie comunque la
 *     manutenzione (funzione di shutdown).
 *
 * La radice su cui lavora è config('update.root_path') o base_path(): i
 * test usano una cartella temporanea con un'app finta.
 */
class UpdateService
{
    private const LOCK_FILE = 'storage/framework/aggiornamento.lock';

    private const MAINTENANCE_MARKER = 'storage/framework/down';

    /** File che identificano la radice di un pacchetto valido. */
    private const FILE_PACCHETTO = ['artisan', 'VERSION', 'vendor/autoload.php', 'public/index.php', 'bootstrap/app.php'];

    private string $root;

    /** @var resource|null */
    private $lockHandle = null;

    private bool $ownsUpdate = false;

    /** Backup dei file della copia in corso: l'handler di shutdown lo usa per il rollback. */
    private ?string $backupFileInCorso = null;

    private bool $copiaIniziata = false;

    private bool $shutdownRegistrato = false;

    public function __construct(?string $root = null)
    {
        $root = $root ?? ((string) config('update.root_path') ?: base_path());
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function versioneCorrente(): string
    {
        $file = $this->root.'/VERSION';
        $v = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $v !== '' ? $v : (string) config('update.version', '0.1.0');
    }

    public function selfUpdateAbilitato(): bool
    {
        return (bool) config('update.self_update_enabled', true);
    }

    // ------------------------------------------------------------------
    // Stato e controllo release
    // ------------------------------------------------------------------

    /**
     * Controlla la presenza di una nuova release su GitHub.
     *
     * @return array{disponibile: bool, corrente: string, ultima: ?string, tag: ?string, note: ?string, url: ?string, pacchetto: bool, errore: ?string}
     */
    public function controllaAggiornamenti(): array
    {
        $corrente = $this->versioneCorrente();
        $base = [
            'disponibile' => false, 'corrente' => $corrente, 'ultima' => null, 'tag' => null,
            'note' => null, 'url' => null, 'pacchetto' => false, 'errore' => null,
        ];

        try {
            $release = $this->ultimaRelease();
            if ($release === null) {
                return ['errore' => 'Nessuna release trovata.'] + $base;
            }

            $tag = (string) ($release['tag_name'] ?? '');
            $ultima = ltrim($tag, 'v');

            return [
                'disponibile' => $ultima !== '' && version_compare($ultima, $corrente, '>'),
                'corrente' => $corrente,
                'ultima' => $ultima,
                'tag' => $tag,
                'note' => $release['body'] ?? null,
                'url' => $release['html_url'] ?? null,
                'pacchetto' => $this->assetPacchetto($release) !== null,
                'errore' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('UpdateService: controllo aggiornamenti fallito', ['error' => $e->getMessage()]);

            return ['errore' => 'Impossibile contattare GitHub: '.$e->getMessage()] + $base;
        }
    }

    /**
     * Requisiti per aggiornare, come Pinakes: righe nome/richiesto/attuale/ok.
     *
     * @return array{ok: bool, righe: list<array{nome: string, richiesto: string, attuale: string, ok: bool}>}
     */
    public function requisiti(bool $controllaLock = true): array
    {
        $righe = [];

        $minPhp = (string) config('update.min_php', '8.3.0');
        $righe[] = ['nome' => 'PHP', 'richiesto' => '≥ '.$minPhp, 'attuale' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, $minPhp, '>=')];
        $righe[] = ['nome' => 'Estensione zip', 'richiesto' => 'presente', 'attuale' => class_exists(ZipArchive::class) ? 'presente' : 'assente', 'ok' => class_exists(ZipArchive::class)];

        foreach (['' => 'Radice dell\'app', 'app' => 'Cartella app/', 'vendor' => 'Cartella vendor/', 'public' => 'Cartella public/', 'bootstrap/cache' => 'bootstrap/cache', 'storage' => 'Cartella storage/'] as $rel => $nome) {
            $path = $rel === '' ? $this->root : $this->root.'/'.$rel;
            $ok = is_dir($path) && is_writable($path);
            $righe[] = ['nome' => $nome, 'richiesto' => 'scrivibile', 'attuale' => $ok ? 'scrivibile' : 'NON scrivibile ('.$path.')', 'ok' => $ok];
        }

        $minimo = (int) config('update.min_free_bytes', 200 * 1024 * 1024);
        $libero = (float) (@disk_free_space($this->root) ?: 0);
        $righe[] = ['nome' => 'Spazio libero', 'richiesto' => 'almeno '.$this->formatoByte($minimo), 'attuale' => $libero > 0 ? $this->formatoByte($libero) : 'non rilevabile', 'ok' => $libero >= $minimo];

        // disk_free_space() guarda il filesystem: su cPanel può dire decine
        // di GB liberi con la quota dell'account esaurita. Solo una scrittura
        // vera risponde.
        $prova = $this->provaScrittura((int) config('update.probe_bytes', 1024 * 1024));
        $righe[] = ['nome' => 'Prova di scrittura', 'richiesto' => $this->formatoByte((int) config('update.probe_bytes', 1024 * 1024)), 'attuale' => $prova ?? 'riuscita', 'ok' => $prova === null];

        if ($controllaLock) {
            $inCorso = $this->inCorso();
            $righe[] = ['nome' => 'Nessun aggiornamento in corso', 'richiesto' => 'libero', 'attuale' => $inCorso ? 'un aggiornamento è in corso' : 'libero', 'ok' => ! $inCorso];
        }

        return ['ok' => ! in_array(false, array_column($righe, 'ok'), true), 'righe' => $righe];
    }

    /** Un altro processo tiene il lock dell'aggiornamento? */
    public function inCorso(): bool
    {
        $lock = $this->root.'/'.self::LOCK_FILE;
        if (! is_file($lock)) {
            return false;
        }
        $h = @fopen($lock, 'r');
        if ($h === false) {
            return false;
        }
        try {
            if (flock($h, LOCK_SH | LOCK_NB)) {
                flock($h, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($h);
        }
    }

    /**
     * Stato per il polling della pagina durante e dopo un aggiornamento.
     *
     * @return array{versione: string, in_corso: bool, manutenzione: bool, manutenzione_scaduta: bool, ultimo: ?array<string, mixed>}
     */
    public function stato(): array
    {
        $ultimo = UpdateLog::query()->orderByDesc('avviato_at')->first();

        return [
            'versione' => $this->versioneCorrente(),
            'in_corso' => $this->inCorso(),
            'manutenzione' => $this->inManutenzione(),
            'manutenzione_scaduta' => $this->manutenzioneScaduta(),
            'ultimo' => $ultimo?->only(['id', 'from_version', 'to_version', 'stato', 'messaggio_errore', 'avviato_at', 'completato_at']),
        ];
    }

    public function inManutenzione(): bool
    {
        return is_file($this->root.'/'.self::MAINTENANCE_MARKER)
            || is_file($this->root.'/storage/framework/maintenance.php');
    }

    /** Manutenzione accesa da troppo tempo e nessun update in corso: resto di un update interrotto. */
    public function manutenzioneScaduta(): bool
    {
        if (! $this->inManutenzione() || $this->inCorso()) {
            return false;
        }
        $marker = $this->root.'/'.self::MAINTENANCE_MARKER;
        $eta = time() - (int) (@filemtime($marker) ?: time());

        return $eta > (int) config('update.maintenance_stale_minutes', 30) * 60;
    }

    /** Toglie una manutenzione scaduta. Ritorna false se non c'era nulla da sbloccare. */
    public function sbloccaManutenzione(): bool
    {
        if (! $this->manutenzioneScaduta()) {
            return false;
        }
        $this->manutenzione(false);
        Log::warning('UpdateService: manutenzione scaduta rimossa manualmente');

        return true;
    }

    /**
     * Backup presenti sul disco di spatie/laravel-backup e dump PHP dell'updater.
     *
     * @return list<array{nome: string, percorso: string, byte: int, data: int}>
     */
    public function elencoBackup(): array
    {
        $cartelle = [
            $this->root.'/storage/app/private/'.(string) config('backup.backup.name', config('app.name')),
            $this->root.'/'.trim((string) config('update.db_backup_path', 'storage/app/private/aggiornamenti'), '/'),
        ];
        $file = [];
        foreach ($cartelle as $cartella) {
            if (! is_dir($cartella)) {
                continue;
            }
            foreach (File::files($cartella) as $f) {
                if (! preg_match('/\.(zip|sql|gz)$/i', $f->getFilename())) {
                    continue;
                }
                $file[] = ['nome' => $f->getFilename(), 'percorso' => $f->getPathname(), 'byte' => $f->getSize(), 'data' => $f->getMTime()];
            }
        }
        usort($file, fn ($a, $b) => $b['data'] <=> $a['data']);

        return $file;
    }

    // ------------------------------------------------------------------
    // Aggiornamento
    // ------------------------------------------------------------------

    /**
     * Applica l'aggiornamento alla versione indicata (dalla release GitHub)
     * oppure da un pacchetto zip locale ($pacchettoLocale): in quel caso la
     * versione è quella dichiarata dal pacchetto.
     *
     * @return array{success: bool, error: ?string, log_id: ?string, versione: ?string}
     */
    public function applicaAggiornamento(string $versione, ?Utente $esecutore = null, ?string $pacchettoLocale = null): array
    {
        try {
            if (! $this->acquisisciLock()) {
                return ['success' => false, 'error' => 'Un altro aggiornamento è già in corso.', 'log_id' => null, 'versione' => null];
            }
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'log_id' => null, 'versione' => null];
        }

        @set_time_limit(0);
        ignore_user_abort(true);
        $this->alzaMemoria();

        // Il lock è già nostro: i requisiti si controllano senza quella riga
        $req = $this->requisiti(controllaLock: false);
        if (! $req['ok']) {
            $mancanti = array_map(fn ($r) => $r['nome'].': '.$r['attuale'], array_filter($req['righe'], fn ($r) => ! $r['ok']));
            $this->rilasciaLock();

            return ['success' => false, 'error' => 'Requisiti non soddisfatti: '.implode('; ', $mancanti), 'log_id' => null, 'versione' => null];
        }

        $log = UpdateLog::create([
            'from_version' => $this->versioneCorrente(),
            'to_version' => ltrim($versione, 'v'),
            'stato' => UpdateLog::STATO_AVVIATO,
            'eseguito_da' => $esecutore?->id,
        ]);

        $this->ownsUpdate = true;
        $this->registraShutdown();

        $tmp = $this->tempPath();
        File::ensureDirectoryExists($tmp);
        $backupFile = null;
        $estrazione = null;
        $zipScaricato = null;
        $copiaIniziata = false;

        try {
            // 1. Backup database
            $backupDb = $this->backupDatabase();
            $log->update(['backup_path' => $backupDb]);

            // 2. Pacchetto
            if ($pacchettoLocale !== null) {
                $zip = $pacchettoLocale;
            } else {
                $zipScaricato = $tmp.'/pacchetto_'.ltrim($versione, 'v').'.zip';
                $this->scaricaPacchetto($versione, $zipScaricato);
                $zip = $zipScaricato;
            }

            // 3. Estrazione e riconoscimento della sorgente
            $estrazione = $tmp.'/extract_'.now()->format('YmdHis');
            $sorgente = $this->estrai($zip, $estrazione);
            $versionePacchetto = trim((string) file_get_contents($sorgente.'/VERSION'));
            if ($pacchettoLocale !== null) {
                if (version_compare($versionePacchetto, $this->versioneCorrente(), '<')) {
                    throw new RuntimeException("Il pacchetto dichiara la versione {$versionePacchetto}, più vecchia di quella installata (".$this->versioneCorrente().'): non si torna indietro con l\'aggiornamento (le migration non sono reversibili). Ripristina da un backup se serve.');
                }
                $versione = $versionePacchetto;
                $log->update(['to_version' => $versione]);
            } elseif (ltrim($versione, 'v') !== $versionePacchetto) {
                throw new RuntimeException("Il pacchetto dichiara la versione {$versionePacchetto}, non ".ltrim($versione, 'v').'.');
            }

            // 4. Verifiche a secco
            $nonScrivibili = $this->destinazioniNonScrivibili($sorgente);
            if ($nonScrivibili !== []) {
                throw new RuntimeException('Percorsi non scrivibili dall\'utente del web server: '.implode(', ', array_slice($nonScrivibili, 0, 15)).(count($nonScrivibili) > 15 ? ' …' : '').'. Correggi i permessi oppure aggiorna da riga di comando: php artisan app:aggiorna');
            }
            $this->verificaSpazio($this->dimensioneCartella($sorgente) * 2 + 50 * 1024 * 1024);

            // 5. Manutenzione, backup file, copia, orfani
            $this->manutenzione(true);
            $backupFile = $this->backupFile($sorgente, $tmp.'/filebackup_'.now()->format('YmdHis'));
            $this->backupFileInCorso = $backupFile;
            $copiaIniziata = $this->copiaIniziata = true;
            $this->copia($sorgente, $this->root);
            $this->rimuoviOrfani($sorgente);

            // 6. Post-installazione
            $this->postInstallazione();
            $this->manutenzione(false);

            $log->update(['stato' => UpdateLog::STATO_COMPLETATO, 'completato_at' => now()]);
            $this->pulisci([$estrazione, $backupFile, $zipScaricato]);

            return ['success' => true, 'error' => null, 'log_id' => $log->id, 'versione' => $versione];
        } catch (\Throwable $e) {
            Log::error('UpdateService: aggiornamento fallito', ['versione' => $versione, 'error' => $e->getMessage()]);

            $stato = UpdateLog::STATO_FALLITO;
            $messaggio = $e->getMessage();
            $rollbackFallito = false;
            if ($copiaIniziata && $backupFile !== null) {
                try {
                    $this->ripristinaFile($backupFile);
                    $stato = UpdateLog::STATO_ROLLBACK;
                    // I file sono tornati indietro; il database no (le migration
                    // già eseguite restano): il backup del passo 1 serve a questo
                    $messaggio .= ' File ripristinati dal backup. Se le migration erano già partite, il database va ripristinato dal backup: '.($log->backup_path ?? 'vedi storage/app/private').'.';
                } catch (\Throwable $r) {
                    Log::critical('UpdateService: rollback fallito', ['error' => $r->getMessage(), 'backup' => $backupFile]);
                    $rollbackFallito = true;
                    $messaggio .= ' ATTENZIONE: il ripristino dei file è fallito ('.$r->getMessage().'). Il sito resta in manutenzione; i file precedenti sono in '.$backupFile.' (.voci.json elenca cosa rimettere).';
                    $backupFile = null; // da conservare per il ripristino manuale
                }
            }
            if (! $rollbackFallito) {
                $this->manutenzione(false);
            }

            $log->update(['stato' => $stato, 'messaggio_errore' => $messaggio, 'completato_at' => now()]);
            $this->pulisci([$estrazione, $backupFile, $zipScaricato]);

            return ['success' => false, 'error' => $messaggio, 'log_id' => $log->id, 'versione' => $versione];
        } finally {
            $this->ownsUpdate = false;
            $this->copiaIniziata = false;
            $this->backupFileInCorso = null;
            $this->rilasciaLock();
        }
    }

    // ------------------------------------------------------------------
    // Release GitHub e pacchetto
    // ------------------------------------------------------------------

    /**
     * Asset del pacchetto nella release: <repo>-<tag>.zip con il digest
     * sha256 che l'API GitHub pubblica per ogni asset.
     *
     * @param  array<string, mixed>  $release
     * @return array{url: string, nome: string, digest: ?string, byte: int}|null
     */
    public function assetPacchetto(array $release): ?array
    {
        $repo = (string) config('update.github.repo');
        $tag = (string) ($release['tag_name'] ?? '');
        $atteso = strtolower("{$repo}-{$tag}.zip");

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (! is_array($asset) || strtolower((string) ($asset['name'] ?? '')) !== $atteso) {
                continue;
            }
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $digest = $asset['digest'] ?? null;

            return ['url' => $url, 'nome' => (string) $asset['name'], 'digest' => is_string($digest) ? $digest : null, 'byte' => (int) ($asset['size'] ?? 0)];
        }

        return null;
    }

    /**
     * Scarica il pacchetto della release su $destinazione verificandone lo
     * sha256 con il digest dell'API. Senza digest valido rifiuta (fail-closed):
     * un file .sha256 a fianco dello zip arriverebbe dallo stesso CDN e non
     * proverebbe nulla.
     */
    public function scaricaPacchetto(string $versione, string $destinazione): string
    {
        $tag = str_starts_with($versione, 'v') ? $versione : 'v'.$versione;
        $release = $this->releasePerTag($tag);
        if ($release === null) {
            throw new RuntimeException("Release {$tag} non trovata su GitHub.");
        }
        $asset = $this->assetPacchetto($release);
        if ($asset === null) {
            throw new RuntimeException("La release {$tag} non contiene il pacchetto installabile ".config('update.github.repo')."-{$tag}.zip: aggiornamento annullato.");
        }

        $atteso = null;
        if (is_string($asset['digest']) && stripos($asset['digest'], 'sha256:') === 0) {
            $candidato = strtolower(substr($asset['digest'], 7));
            if (preg_match('/^[0-9a-f]{64}$/', $candidato)) {
                $atteso = $candidato;
            }
        }
        if ($atteso === null) {
            throw new RuntimeException('Verifica di integrità impossibile: la release non pubblica un digest sha256 valido per il pacchetto. Installazione rifiutata.');
        }

        $parziale = $destinazione.'.part';
        @unlink($parziale);
        // Download anonimo e diretto su file: il pacchetto non passa mai per
        // una stringa PHP (memoria) e il token non viene inviato al CDN
        $resp = Http::withHeaders(['User-Agent' => 'GestioneAssociazioni-Updater', 'Accept' => 'application/octet-stream'])
            ->timeout((int) config('update.download_timeout', 300))
            ->sink($parziale)
            ->get($asset['url']);
        if (! $resp->successful()) {
            @unlink($parziale);
            throw new RuntimeException('Download del pacchetto fallito: HTTP '.$resp->status());
        }
        $byte = is_file($parziale) ? (int) filesize($parziale) : 0;
        if ($byte < 1000) {
            @unlink($parziale);
            throw new RuntimeException('Download del pacchetto fallito: file troppo piccolo ('.$byte.' byte).');
        }

        $reale = (string) hash_file('sha256', $parziale);
        if (! hash_equals($atteso, $reale)) {
            @unlink($parziale);
            throw new RuntimeException('Verifica di integrità fallita: lo sha256 del pacchetto scaricato non corrisponde al digest pubblicato da GitHub.');
        }
        if (! @rename($parziale, $destinazione)) {
            @unlink($parziale);
            throw new RuntimeException('Impossibile salvare il pacchetto in '.$destinazione);
        }

        return $reale;
    }

    /** @return array<string, mixed>|null */
    private function ultimaRelease(): ?array
    {
        $owner = config('update.github.owner');
        $repo = config('update.github.repo');
        $client = Http::withHeaders($this->headerGitHub())->timeout(15);

        if (config('update.channel') === 'rc') {
            $resp = $client->get("https://api.github.com/repos/{$owner}/{$repo}/releases", ['per_page' => 10]);
            abort_unless($resp->successful(), 502, 'GitHub API: '.$resp->status());

            return collect($resp->json())->reject(fn ($r) => ($r['draft'] ?? false))->first();
        }

        $resp = $client->get("https://api.github.com/repos/{$owner}/{$repo}/releases/latest");
        if ($resp->status() === 404) {
            return null;
        }
        abort_unless($resp->successful(), 502, 'GitHub API: '.$resp->status());

        return $resp->json();
    }

    /** @return array<string, mixed>|null */
    private function releasePerTag(string $tag): ?array
    {
        $owner = config('update.github.owner');
        $repo = config('update.github.repo');
        $resp = Http::withHeaders($this->headerGitHub())->timeout(15)
            ->get("https://api.github.com/repos/{$owner}/{$repo}/releases/tags/{$tag}");
        if ($resp->status() === 404) {
            return null;
        }
        if (! $resp->successful()) {
            throw new RuntimeException('GitHub API: HTTP '.$resp->status());
        }

        return $resp->json();
    }

    /** @return array<string, string> */
    private function headerGitHub(): array
    {
        $h = ['User-Agent' => 'GestioneAssociazioni-Updater', 'Accept' => 'application/vnd.github+json'];
        $token = config('update.github.token');
        if (filled($token)) {
            $h['Authorization'] = 'Bearer '.$token;
        }

        return $h;
    }

    // ------------------------------------------------------------------
    // Passi interni
    // ------------------------------------------------------------------

    /**
     * Backup del database prima di toccare qualsiasi cosa: spatie/laravel-backup
     * (mysqldump) e, se non disponibile, dump in PHP puro. Senza backup non si
     * aggiorna.
     */
    protected function backupDatabase(): string
    {
        try {
            $esito = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
            if ($esito === 0) {
                return 'spatie-backup';
            }
            Log::warning('UpdateService: backup:run fallito, uso il dump PHP', ['output' => Artisan::output()]);
        } catch (\Throwable $e) {
            Log::warning('UpdateService: backup:run non disponibile, uso il dump PHP', ['error' => $e->getMessage()]);
        }

        $cartella = $this->root.'/'.trim((string) config('update.db_backup_path', 'storage/app/private/aggiornamenti'), '/');
        $file = $cartella.'/db-'.now()->format('Y-m-d_His').'.sql.gz';

        return (new DumpDatabasePhp)->dump($file);
    }

    /** Estrae lo zip e ritorna la cartella sorgente (radice del pacchetto). */
    private function estrai(string $zipPath, string $destinazione): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Il pacchetto non è uno zip valido.');
        }
        $byteDecompressi = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $segmenti = preg_split('/[\\\\\/]+/', $entry) ?: [];
            if ($entry === '' || in_array('..', $segmenti, true) || str_starts_with($entry, '/') || str_starts_with($entry, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $entry)) {
                $zip->close();
                throw new RuntimeException('Percorso non valido nel pacchetto: '.$entry);
            }
            $byteDecompressi += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        $this->verificaSpazio($byteDecompressi + 50 * 1024 * 1024);
        File::deleteDirectory($destinazione);
        File::ensureDirectoryExists($destinazione);
        if (! $zip->extractTo($destinazione)) {
            $zip->close();
            throw new RuntimeException('Estrazione del pacchetto fallita (spazio o permessi in '.$destinazione.').');
        }
        $zip->close();

        $sorgente = $this->trovaSorgente($destinazione);
        if ($sorgente === null) {
            throw new RuntimeException('Pacchetto non valido: manca uno tra '.implode(', ', self::FILE_PACCHETTO).'.');
        }

        return $sorgente;
    }

    /** La radice dell'app dentro l'estrazione: la cartella stessa o la sua unica sottocartella. */
    private function trovaSorgente(string $estrazione): ?string
    {
        $candidati = [$estrazione];
        $dirs = File::directories($estrazione);
        if (count($dirs) === 1) {
            $candidati[] = $dirs[0];
        }
        foreach ($candidati as $c) {
            $ok = true;
            foreach (self::FILE_PACCHETTO as $f) {
                if (! is_file($c.'/'.$f)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return rtrim(str_replace('\\', '/', $c), '/');
            }
        }

        return null;
    }

    /**
     * Prova a secco della copia: ogni destinazione che il processo PHP non
     * potrebbe scrivere. Un file esistente deve essere scrivibile; un file
     * nuovo richiede scrivibile il primo antenato esistente. Dove il
     * proprietario è lo stesso utente PHP si prova prima un chmod u+w.
     *
     * @return list<string>
     */
    public function destinazioniNonScrivibili(string $sorgente): array
    {
        $problemi = [];
        foreach ($this->fileDelPacchetto($sorgente) as $rel) {
            $dest = $this->root.'/'.$rel;
            if (file_exists($dest)) {
                if (! $this->scrivibileAncheDopoChmod($dest, is_dir($dest) ? 0775 : 0664)) {
                    $problemi[$rel] = true;
                }

                continue;
            }
            $antenato = dirname($dest);
            while (! file_exists($antenato) && $antenato !== $this->root && strlen($antenato) > strlen($this->root)) {
                $antenato = dirname($antenato);
            }
            if (! $this->scrivibileAncheDopoChmod($antenato, 0775)) {
                $problemi[str_replace($this->root.'/', '', $antenato) ?: '.'] = true;
            }
        }
        $lista = array_keys($problemi);
        sort($lista);

        return $lista;
    }

    /** Scrivibile com'è, oppure dopo un chmod u+w (possibile solo se il file è nostro). */
    private function scrivibileAncheDopoChmod(string $path, int $modo): bool
    {
        if (is_writable($path)) {
            return true;
        }
        @chmod($path, $modo);
        clearstatcache(true, $path);

        return is_writable($path);
    }

    /**
     * Percorsi relativi di tutto ciò che il pacchetto copierebbe (file e
     * cartelle), esclusi skip_paths e, se già presenti, preserve_paths.
     *
     * @return list<string>
     */
    private function fileDelPacchetto(string $sorgente): array
    {
        $out = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sorgente, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iter as $item) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($sorgente) + 1));
            if ($this->matchPrefix($rel, (array) config('update.skip_paths', []))) {
                continue;
            }
            if ($this->matchPrefix($rel, (array) config('update.preserve_paths', [])) && file_exists($this->root.'/'.$rel)) {
                continue;
            }
            $out[] = $rel;
        }

        return $out;
    }

    /** Copia del pacchetto sulla radice, saltando skip_paths e i preserve_paths già presenti. */
    private function copia(string $sorgente, string $dest): void
    {
        foreach ($this->fileDelPacchetto($sorgente) as $rel) {
            $da = $sorgente.'/'.$rel;
            $a = $dest.'/'.$rel;
            if (is_dir($da)) {
                File::ensureDirectoryExists($a);

                continue;
            }
            File::ensureDirectoryExists(dirname($a));
            if (is_link($a)) {
                @unlink($a);
            }
            if (! @copy($da, $a)) {
                throw new RuntimeException('Copia fallita: '.$rel);
            }
        }
    }

    /**
     * Backup di tutto ciò che la copia toccherebbe: ogni voce di primo
     * livello del pacchetto già presente nella radice (app/, vendor/, public/
     * senza i preserve, artisan, VERSION …). Serve al rollback.
     */
    private function backupFile(string $sorgente, string $backupDir): string
    {
        File::ensureDirectoryExists($backupDir);
        $voci = [];
        foreach ($this->fileDelPacchetto($sorgente) as $rel) {
            $voci[explode('/', $rel, 2)[0]] = true;
        }
        $elenco = [];
        foreach (array_keys($voci) as $voce) {
            $src = $this->root.'/'.$voce;
            if (! file_exists($src)) {
                $elenco[] = ['voce' => $voce, 'esisteva' => false];

                continue;
            }
            if (is_dir($src)) {
                $this->copiaCartella($src, $backupDir.'/'.$voce, (array) config('update.preserve_paths', []), $voce);
            } else {
                File::copy($src, $backupDir.'/'.$voce);
            }
            $elenco[] = ['voce' => $voce, 'esisteva' => true];
        }
        File::put($backupDir.'/.voci.json', (string) json_encode($elenco));

        return $backupDir;
    }

    /** Rollback: rimette ogni voce com'era prima della copia (e toglie quelle che non esistevano). */
    protected function ripristinaFile(string $backupDir): void
    {
        $elenco = json_decode((string) file_get_contents($backupDir.'/.voci.json'), true) ?: [];
        foreach ($elenco as $v) {
            $voce = (string) $v['voce'];
            $dest = $this->root.'/'.$voce;
            if (! $v['esisteva']) {
                is_dir($dest) ? File::deleteDirectory($dest) : @unlink($dest);

                continue;
            }
            $src = $backupDir.'/'.$voce;
            if (is_dir($src)) {
                // Sostituzione completa, ma senza toccare i preserve (storage, upload)
                $this->svuotaTranne($dest, (array) config('update.preserve_paths', []), $voce);
                $this->copiaCartella($src, $dest, [], $voce);
            } elseif (is_file($src)) {
                File::copy($src, $dest);
            }
        }
    }

    /**
     * Nelle cartelle di codice cancella ciò che il pacchetto non contiene più
     * (classi rimosse, dipendenze dismesse), mai nei preserve_paths.
     */
    private function rimuoviOrfani(string $sorgente): void
    {
        foreach ((array) config('update.orphan_dirs', []) as $dir) {
            $attuale = $this->root.'/'.$dir;
            $nuovo = $sorgente.'/'.$dir;
            if (! is_dir($attuale) || ! is_dir($nuovo)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($attuale, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $item) {
                $rel = $dir.'/'.str_replace('\\', '/', substr($item->getPathname(), strlen($attuale) + 1));
                if ($this->matchPrefix($rel, (array) config('update.preserve_paths', []))) {
                    continue;
                }
                if (! file_exists($nuovo.'/'.substr($rel, strlen($dir) + 1))) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }
        }
    }

    /** Comandi dopo la copia dei file. */
    /**
     * Comandi dopo la copia dei file, in un processo PHP separato: il
     * processo corrente ha in memoria il kernel e le classi della versione
     * vecchia (comandi nuovi non registrati, classi miste). Prima vengono
     * tolte le cache di bootstrap (config, rotte, eventi): quelle di
     * composer (packages/services) arrivano fresche dal pacchetto.
     */
    protected function postInstallazione(): void
    {
        foreach (['config.php', 'routes-v7.php', 'events.php', 'routes.scanned.php', 'events.scanned.php'] as $cache) {
            @unlink($this->root.'/bootstrap/cache/'.$cache);
        }

        $this->artisanSeparato(['optimize:clear']);
        $this->artisanSeparato(['migrate', '--force', '--no-interaction']);
        $this->artisanSeparato(['app:aggiorna-permessi']);
        try {
            $this->artisanSeparato(['queue:restart']);
        } catch (\Throwable $e) {
            Log::warning('UpdateService: queue:restart non riuscito', ['error' => $e->getMessage()]);
        }
        if (! file_exists($this->root.'/public/storage')) {
            try {
                $this->artisanSeparato(['storage:link', '--force']);
            } catch (\Throwable $e) {
                Log::warning('UpdateService: storage:link non riuscito', ['error' => $e->getMessage()]);
            }
        }
    }

    /** @param  list<string>  $argomenti */
    private function artisanSeparato(array $argomenti): void
    {
        $processo = new Process(array_merge([PHP_BINARY, $this->root.'/artisan'], $argomenti), $this->root, null, null, 600);
        $processo->run();
        if (! $processo->isSuccessful()) {
            throw new RuntimeException('php artisan '.implode(' ', $argomenti).' fallito: '.trim($processo->getErrorOutput().' '.$processo->getOutput()));
        }
    }

    /**
     * Manutenzione: artisan down/up sull'installazione reale; nei test (radice
     * finta) solo un marcatore, così lo si può verificare.
     */
    protected function manutenzione(bool $attiva): void
    {
        $marker = $this->root.'/'.self::MAINTENANCE_MARKER;
        if ($this->rootReale()) {
            if ($attiva) {
                Artisan::call('down', ['--render' => 'errors::503', '--retry' => 60]);
            } else {
                Artisan::call('up');
            }
        }
        if ($attiva) {
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, (string) time());
        } else {
            @unlink($marker);
        }
    }

    private function rootReale(): bool
    {
        return realpath($this->root) === realpath(base_path());
    }

    /** Un errore fatale a metà update non deve lasciare il sito in manutenzione. */
    private function registraShutdown(): void
    {
        if ($this->shutdownRegistrato) {
            return;
        }
        $this->shutdownRegistrato = true;
        register_shutdown_function(function (): void {
            if (! $this->ownsUpdate) {
                return;
            }
            $errore = error_get_last();
            if ($errore !== null && in_array($errore['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Log::critical('UpdateService: errore fatale durante l\'aggiornamento', $errore);
            }
            // Copia a metà: si prova il rollback; se fallisce il sito resta in
            // manutenzione invece di andare online con file misti
            $ripristinato = true;
            if ($this->copiaIniziata && $this->backupFileInCorso !== null) {
                try {
                    $this->ripristinaFile($this->backupFileInCorso);
                    UpdateLog::query()->where('stato', UpdateLog::STATO_AVVIATO)->orderByDesc('avviato_at')->first()
                        ?->update(['stato' => UpdateLog::STATO_ROLLBACK, 'messaggio_errore' => 'Interrotto da un errore fatale; file ripristinati dal backup.', 'completato_at' => now()]);
                } catch (\Throwable $r) {
                    $ripristinato = false;
                    Log::critical('UpdateService: rollback dopo errore fatale fallito, sito lasciato in manutenzione', ['error' => $r->getMessage(), 'backup' => $this->backupFileInCorso]);
                }
            }
            if ($ripristinato) {
                try {
                    $this->manutenzione(false);
                } catch (\Throwable) {
                }
            }
            $this->rilasciaLock();
        });
    }

    private function acquisisciLock(): bool
    {
        $lock = $this->root.'/'.self::LOCK_FILE;
        File::ensureDirectoryExists(dirname($lock));
        $h = @fopen($lock, 'c');
        if ($h === false) {
            throw new RuntimeException('Impossibile aprire il file di lock '.$lock.' (permessi: forse creato da un altro utente, es. aggiornamento lanciato da CLI). Correggi il proprietario o cancellalo.');
        }
        if (! flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);

            return false;
        }
        ftruncate($h, 0);
        fwrite($h, (string) getmypid());
        fflush($h);
        $this->lockHandle = $h;

        return true;
    }

    private function rilasciaLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    private function verificaSpazio(int $byteNecessari): void
    {
        $libero = (float) (@disk_free_space($this->root) ?: 0);
        if ($libero > 0 && $libero < $byteNecessari) {
            throw new RuntimeException(sprintf('Spazio insufficiente: servono almeno %s, liberi %s.', $this->formatoByte($byteNecessari), $this->formatoByte($libero)));
        }
        $prova = $this->provaScrittura(min($byteNecessari, 16 * 1024 * 1024));
        if ($prova !== null) {
            throw new RuntimeException('Prova di scrittura fallita: '.$prova);
        }
    }

    /** Scrive e cancella un file di prova nell'area di lavoro. Null se riuscita, altrimenti il motivo. */
    private function provaScrittura(int $byte): ?string
    {
        $dir = $this->tempPath();
        try {
            File::ensureDirectoryExists($dir);
        } catch (\Throwable) {
            return 'impossibile creare '.$dir;
        }
        $file = $dir.'/.prova-'.getmypid();
        $h = @fopen($file, 'wb');
        if ($h === false) {
            return 'impossibile scrivere in '.$dir;
        }
        $blocco = str_repeat('0', 65536);
        $scritti = 0;
        while ($scritti < $byte) {
            $n = @fwrite($h, substr($blocco, 0, min(65536, $byte - $scritti)));
            if ($n === false || $n === 0) {
                fclose($h);
                @unlink($file);

                return 'spazio o quota esauriti in '.$dir;
            }
            $scritti += $n;
        }
        fclose($h);
        @unlink($file);

        return null;
    }

    private function tempPath(): string
    {
        return $this->root.'/'.trim((string) config('update.temp_path', 'storage/app/updates'), '/');
    }

    /** @param  list<string|null>  $percorsi */
    private function pulisci(array $percorsi): void
    {
        foreach ($percorsi as $p) {
            if ($p === null) {
                continue;
            }
            is_dir($p) ? File::deleteDirectory($p) : @unlink($p);
        }
    }

    private function alzaMemoria(): void
    {
        $limite = (string) ini_get('memory_limit');
        if ($limite !== '-1' && $this->byteDaIni($limite) < 256 * 1024 * 1024) {
            @ini_set('memory_limit', '256M');
        }
    }

    private function byteDaIni(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function dimensioneCartella(string $dir): int
    {
        $tot = 0;
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            $tot += $f->getSize();
        }

        return $tot;
    }

    /**
     * @param  list<string>  $preserve  prefissi (relativi alla radice) da non copiare
     */
    private function copiaCartella(string $da, string $a, array $preserve, string $relBase): void
    {
        File::ensureDirectoryExists($a);
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($da, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iter as $item) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($da) + 1));
            if ($preserve !== [] && $this->matchPrefix($relBase.'/'.$rel, $preserve)) {
                continue;
            }
            if ($item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                File::ensureDirectoryExists($a.'/'.$rel);
            } else {
                File::ensureDirectoryExists(dirname($a.'/'.$rel));
                File::copy($item->getPathname(), $a.'/'.$rel);
            }
        }
    }

    /** @param  list<string>  $preserve */
    private function svuotaTranne(string $dir, array $preserve, string $relBase): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (File::directories($dir) as $sub) {
            $rel = $relBase.'/'.basename($sub);
            if ($this->matchPrefix($rel, $preserve)) {
                continue;
            }
            is_link($sub) ? @unlink($sub) : File::deleteDirectory($sub);
        }
        foreach (File::files($dir, true) as $f) {
            if (! $this->matchPrefix($relBase.'/'.$f->getFilename(), $preserve)) {
                @unlink($f->getPathname());
            }
        }
    }

    /** @param  list<string>  $prefissi */
    private function matchPrefix(string $rel, array $prefissi): bool
    {
        foreach ($prefissi as $p) {
            $p = trim((string) $p, '/');
            if ($p !== '' && ($rel === $p || str_starts_with($rel, $p.'/'))) {
                return true;
            }
        }

        return false;
    }

    private function formatoByte(float $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($b >= 1024 && $i < 3) {
            $b /= 1024;
            $i++;
        }

        return round($b, 1).' '.$u[$i];
    }
}
