<?php

namespace Tests\Feature\Admin;

use App\Models\UpdateLog;
use App\Services\Update\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Flusso completo dell'updater (come quello di Pinakes) su una radice finta
 * in una cartella temporanea: copia, preserve, orfani, versione, rollback,
 * lock, manutenzione. Backup DB e comandi post-installazione sono sostituiti
 * da un doppio di prova.
 */
class UpdateServiceFlussoTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $pacchetto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/updater_root_'.uniqid();
        $this->creaAppFinta($this->root, '0.1.0');
        $this->pacchetto = sys_get_temp_dir().'/updater_pacchetto_'.uniqid().'.zip';
        $this->creaPacchetto($this->pacchetto, '0.2.0');
        config(['update.min_free_bytes' => 1024]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        @unlink($this->pacchetto);
        parent::tearDown();
    }

    public function test_aggiornamento_da_pacchetto_copia_preserva_e_pulisce(): void
    {
        $updater = new UpdateServiceDiProva($this->root);

        $esito = $updater->applicaAggiornamento('0.0.0', null, $this->pacchetto);

        $this->assertTrue($esito['success'], (string) $esito['error']);
        $this->assertSame('0.2.0', $esito['versione']);
        $this->assertSame('0.2.0', trim((string) file_get_contents($this->root.'/VERSION')));

        // File nuovi e aggiornati
        $this->assertFileExists($this->root.'/app/nuovo.php');
        $this->assertSame('v2', file_get_contents($this->root.'/app/comune.php'));
        $this->assertFileExists($this->root.'/vendor/nuovo/lib.php');
        // Orfani rimossi nelle cartelle di codice
        $this->assertFileDoesNotExist($this->root.'/app/vecchio.php');
        $this->assertFileDoesNotExist($this->root.'/vendor/dismesso/lib.php');
        // Preservati: .env, storage, upload
        $this->assertSame('APP_KEY=mia', file_get_contents($this->root.'/.env'));
        $this->assertSame('dati', file_get_contents($this->root.'/storage/app/dati.txt'));
        $this->assertSame('foto', file_get_contents($this->root.'/public/uploads/foto.jpg'));
        // Nei percorsi preservati i file nuovi del pacchetto arrivano (es. un
        // .htaccess di protezione), quelli esistenti non vengono toccati
        $this->assertFileExists($this->root.'/storage/.htaccess-del-pacchetto');
        $this->assertSame('dati', file_get_contents($this->root.'/storage/app/dati.txt'));
        // Manutenzione tolta, lock libero, area di lavoro pulita
        $this->assertFileDoesNotExist($this->root.'/storage/framework/down');
        $this->assertFalse($updater->inCorso());
        $this->assertSame([], File::directories($this->root.'/storage/app/updates'));

        $this->assertDatabaseHas('update_logs', ['from_version' => '0.1.0', 'to_version' => '0.2.0', 'stato' => 'completato', 'backup_path' => 'finto']);
        $this->assertSame(1, $updater->postInstallazioneEseguita);
    }

    public function test_versione_richiesta_diversa_da_quella_del_pacchetto_scaricato_viene_rifiutata_prima_di_copiare(): void
    {
        config(['update.github.owner' => 'o', 'update.github.repo' => 'gestionale-associazioni', 'update.github.token' => null]);
        $byte = (string) file_get_contents($this->pacchetto);
        Http::fake([
            'api.github.com/repos/o/gestionale-associazioni/releases/tags/v0.3.0' => Http::response(['tag_name' => 'v0.3.0', 'assets' => [
                ['name' => 'gestionale-associazioni-v0.3.0.zip', 'browser_download_url' => 'https://github.com/o/p.zip', 'digest' => 'sha256:'.hash('sha256', $byte), 'size' => strlen($byte)],
            ]]),
            'github.com/*' => Http::response($byte, 200),
        ]);
        $updater = new UpdateServiceDiProva($this->root);

        // La release dice 0.3.0 ma il pacchetto dentro dichiara 0.2.0
        $esito = $updater->applicaAggiornamento('0.3.0');

        $this->assertFalse($esito['success']);
        $this->assertStringContainsString('dichiara la versione 0.2.0', (string) $esito['error']);
        $this->assertFileExists($this->root.'/app/vecchio.php');
        $this->assertSame('0.1.0', trim((string) file_get_contents($this->root.'/VERSION')));
        $this->assertDatabaseHas('update_logs', ['stato' => 'fallito']);
    }

    public function test_pacchetto_senza_i_file_essenziali_viene_rifiutato(): void
    {
        $zip = sys_get_temp_dir().'/updater_rotto_'.uniqid().'.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('gestionale-associazioni/VERSION', "0.2.0\n");
        $z->addFromString('gestionale-associazioni/app/x.php', '<?php');
        $z->close();

        try {
            $esito = (new UpdateServiceDiProva($this->root))->applicaAggiornamento('0.0.0', null, $zip);
            $this->assertFalse($esito['success']);
            $this->assertStringContainsString('Pacchetto non valido', (string) $esito['error']);
            $this->assertFileExists($this->root.'/app/vecchio.php');
        } finally {
            @unlink($zip);
        }
    }

    public function test_zip_con_percorsi_fuori_dalla_radice_viene_rifiutato(): void
    {
        $zip = sys_get_temp_dir().'/updater_slip_'.uniqid().'.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('../fuori.php', '<?php');
        $z->close();

        try {
            $esito = (new UpdateServiceDiProva($this->root))->applicaAggiornamento('0.0.0', null, $zip);
            $this->assertFalse($esito['success']);
            $this->assertStringContainsString('Percorso non valido', (string) $esito['error']);
        } finally {
            @unlink($zip);
        }
    }

    public function test_errore_dopo_la_copia_ripristina_i_file_e_segna_rollback(): void
    {
        $updater = new UpdateServiceDiProva($this->root);
        $updater->fallisciPostInstallazione = true;

        $esito = $updater->applicaAggiornamento('0.0.0', null, $this->pacchetto);

        $this->assertFalse($esito['success']);
        $this->assertStringContainsString('migrate fallita', (string) $esito['error']);
        // Com'era prima
        $this->assertSame('0.1.0', trim((string) file_get_contents($this->root.'/VERSION')));
        $this->assertFileExists($this->root.'/app/vecchio.php');
        $this->assertFileDoesNotExist($this->root.'/app/nuovo.php');
        $this->assertSame('v1', file_get_contents($this->root.'/app/comune.php'));
        $this->assertFileExists($this->root.'/vendor/dismesso/lib.php');
        $this->assertFileDoesNotExist($this->root.'/vendor/nuovo/lib.php');
        $this->assertSame('dati', file_get_contents($this->root.'/storage/app/dati.txt'));
        $this->assertFileDoesNotExist($this->root.'/storage/framework/down');
        $this->assertDatabaseHas('update_logs', ['stato' => 'rollback']);
        $this->assertFalse($updater->inCorso());
    }

    public function test_rollback_fallito_lascia_il_sito_in_manutenzione_e_indica_il_backup(): void
    {
        $updater = new UpdateServiceDiProva($this->root);
        $updater->fallisciPostInstallazione = true;
        $updater->fallisciRipristino = true;

        $esito = $updater->applicaAggiornamento('0.0.0', null, $this->pacchetto);

        $this->assertFalse($esito['success']);
        $this->assertStringContainsString('ripristino dei file è fallito', (string) $esito['error']);
        $this->assertStringContainsString('filebackup_', (string) $esito['error']);
        $this->assertFileExists($this->root.'/storage/framework/down', 'Con file misti il sito non deve tornare online');
        $this->assertDatabaseHas('update_logs', ['stato' => 'fallito']);
        // Il backup dei file resta sul disco per il ripristino manuale
        $this->assertNotEmpty(glob($this->root.'/storage/app/updates/filebackup_*'));
    }

    public function test_pacchetto_locale_con_versione_piu_vecchia_viene_rifiutato(): void
    {
        File::put($this->root.'/VERSION', "0.5.0\n");
        $updater = new UpdateServiceDiProva($this->root);

        $esito = $updater->applicaAggiornamento('0.0.0', null, $this->pacchetto); // dichiara 0.2.0

        $this->assertFalse($esito['success']);
        $this->assertStringContainsString('non si torna indietro', (string) $esito['error']);
        $this->assertFileExists($this->root.'/app/vecchio.php');
        $this->assertSame('0.5.0', trim((string) file_get_contents($this->root.'/VERSION')));
    }

    public function test_nome_file_con_due_punti_consecutivi_e_ammesso_ma_il_segmento_padre_no(): void
    {
        // "..": solo come segmento di percorso è pericoloso; dentro un nome di file è legittimo
        $zip = sys_get_temp_dir().'/updater_punti_'.uniqid().'.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE);
        $p = 'gestionale-associazioni/';
        foreach (['VERSION' => "0.2.0\n", 'artisan' => '<?php', 'bootstrap/app.php' => '<?php', 'public/index.php' => '<?php', 'vendor/autoload.php' => '<?php', 'vendor/lib/file..js' => '//'] as $nome => $contenuto) {
            $z->addFromString($p.$nome, $contenuto);
        }
        $z->close();

        try {
            $esito = (new UpdateServiceDiProva($this->root))->applicaAggiornamento('0.0.0', null, $zip);
            $this->assertTrue($esito['success'], (string) $esito['error']);
            $this->assertFileExists($this->root.'/vendor/lib/file..js');
        } finally {
            @unlink($zip);
        }
    }

    public function test_lock_impedisce_due_aggiornamenti_insieme(): void
    {
        File::ensureDirectoryExists($this->root.'/storage/framework');
        $h = fopen($this->root.'/storage/framework/aggiornamento.lock', 'c');
        $this->assertTrue(flock($h, LOCK_EX | LOCK_NB));

        $updater = new UpdateServiceDiProva($this->root);
        $this->assertTrue($updater->inCorso());
        $esito = $updater->applicaAggiornamento('0.0.0', null, $this->pacchetto);

        flock($h, LOCK_UN);
        fclose($h);

        $this->assertFalse($esito['success']);
        $this->assertStringContainsString('già in corso', (string) $esito['error']);
        $this->assertSame(0, UpdateLog::query()->count());
    }

    public function test_manutenzione_scaduta_viene_riconosciuta_e_sbloccata(): void
    {
        $updater = new UpdateServiceDiProva($this->root);
        $marker = $this->root.'/storage/framework/down';
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, '1');
        $this->assertFalse($updater->manutenzioneScaduta(), 'Appena accesa non è scaduta');

        touch($marker, time() - 3600);
        $this->assertTrue($updater->manutenzioneScaduta());
        $this->assertTrue($updater->sbloccaManutenzione());
        $this->assertFileDoesNotExist($marker);
        $this->assertFalse($updater->sbloccaManutenzione());
    }

    public function test_requisiti_segnalano_cartella_non_scrivibile(): void
    {
        $updater = new UpdateServiceDiProva($this->root);
        $this->assertTrue($updater->requisiti()['ok']);

        chmod($this->root.'/vendor', 0555);
        try {
            $req = $updater->requisiti();
            $this->assertFalse($req['ok']);
            $riga = collect($req['righe'])->firstWhere('nome', 'Cartella vendor/');
            $this->assertFalse($riga['ok']);
        } finally {
            chmod($this->root.'/vendor', 0755);
        }
    }

    public function test_la_prova_a_secco_non_segnala_nulla_quando_tutto_e_scrivibile(): void
    {
        $updater = new UpdateServiceDiProva($this->root);
        $estratto = $this->root.'/storage/app/updates/estratto';
        $z = new ZipArchive;
        $z->open($this->pacchetto);
        $z->extractTo($estratto);
        $z->close();

        $this->assertSame([], $updater->destinazioniNonScrivibili($estratto.'/gestionale-associazioni'));
    }

    // ------------------------------------------------------------------

    private function creaAppFinta(string $root, string $versione): void
    {
        foreach (['app', 'bootstrap/cache', 'public/uploads', 'vendor/dismesso', 'storage/app', 'storage/framework', 'config'] as $d) {
            File::ensureDirectoryExists($root.'/'.$d);
        }
        File::put($root.'/VERSION', $versione."\n");
        File::put($root.'/artisan', '<?php');
        File::put($root.'/bootstrap/app.php', '<?php');
        File::put($root.'/public/index.php', '<?php');
        File::put($root.'/vendor/autoload.php', '<?php');
        File::put($root.'/vendor/dismesso/lib.php', '<?php');
        File::put($root.'/app/vecchio.php', '<?php');
        File::put($root.'/app/comune.php', 'v1');
        File::put($root.'/.env', 'APP_KEY=mia');
        File::put($root.'/storage/app/dati.txt', 'dati');
        File::put($root.'/public/uploads/foto.jpg', 'foto');
        File::put($root.'/public/storage', 'link');
    }

    private function creaPacchetto(string $zip, string $versione): void
    {
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $p = 'gestionale-associazioni/';
        $z->addFromString($p.'VERSION', $versione."\n");
        $z->addFromString($p.'artisan', '<?php');
        $z->addFromString($p.'bootstrap/app.php', '<?php');
        $z->addFromString($p.'public/index.php', '<?php');
        $z->addFromString($p.'public/uploads/altro.jpg', 'non deve sovrascrivere');
        $z->addFromString($p.'vendor/autoload.php', '<?php');
        $z->addFromString($p.'vendor/nuovo/lib.php', '<?php');
        $z->addFromString($p.'app/nuovo.php', '<?php');
        $z->addFromString($p.'app/comune.php', 'v2');
        $z->addFromString($p.'.env.example', 'APP_KEY=');
        $z->addFromString($p.'storage/.htaccess-del-pacchetto', 'deny');
        $z->addFromString($p.'storage/app/dati.txt', 'sovrascritto');
        $z->close();
    }
}

/** Doppio di prova: niente backup spatie né comandi artisan sull'app vera. */
class UpdateServiceDiProva extends UpdateService
{
    public bool $fallisciPostInstallazione = false;

    public bool $fallisciRipristino = false;

    public int $postInstallazioneEseguita = 0;

    protected function backupDatabase(): string
    {
        return 'finto';
    }

    protected function postInstallazione(): void
    {
        if ($this->fallisciPostInstallazione) {
            throw new \RuntimeException('migrate fallita');
        }
        $this->postInstallazioneEseguita++;
    }

    protected function ripristinaFile(string $backupDir): void
    {
        if ($this->fallisciRipristino) {
            throw new \RuntimeException('disco pieno durante il ripristino');
        }
        parent::ripristinaFile($backupDir);
    }
}
