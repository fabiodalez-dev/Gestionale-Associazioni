<?php

namespace Tests\Feature\Installer;

use App\Services\Installer\InstallerService;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * InstallerService senza passare dal wizard: scrittura del .env e messaggi
 * del test di connessione.
 */
class InstallerServiceTest extends TestCase
{
    private string $cartella;

    private string $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartella = sys_get_temp_dir().'/installer_service_'.uniqid();
        mkdir($this->cartella);
        $this->env = $this->cartella.'/.env';
        config(['installer.env_file' => $this->env]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cartella);
        parent::tearDown();
    }

    public function test_write_env_scrive_e_rilegge_identica_una_password_con_caratteri_speciali(): void
    {
        file_put_contents($this->env, "APP_NAME=Demo\nDB_PASSWORD=vecchia\nDB_HOST=127.0.0.1\n");
        // "$1" e "\1" sono backreference per preg_replace, \" e \\ sono escape
        // per le virgolette del .env, ${X} è un'interpolazione di phpdotenv:
        // tutto deve sopravvivere alla scrittura e alla rilettura
        $password = 'pa$1ss\\1 "doppia\\" back\\\\slash ${X} #non-commento';

        app(InstallerService::class)->writeEnv(['DB_PASSWORD' => $password, 'DB_USERNAME' => 'utente']);

        $riletto = Dotenv::parse((string) file_get_contents($this->env));
        $this->assertSame($password, $riletto['DB_PASSWORD']);
        $this->assertSame('utente', $riletto['DB_USERNAME']);
        $this->assertSame('Demo', $riletto['APP_NAME'], 'Le altre variabili restano');
        $this->assertSame('127.0.0.1', $riletto['DB_HOST']);
        $this->assertSame(1, substr_count((string) file_get_contents($this->env), 'DB_PASSWORD='), 'La variabile esistente viene sostituita, non duplicata');
    }

    public function test_write_env_senza_file_parte_da_env_example(): void
    {
        app(InstallerService::class)->writeEnv(['DB_DATABASE' => 'demo']);

        $this->assertFileExists($this->env);
        $riletto = Dotenv::parse((string) file_get_contents($this->env));
        $this->assertSame('demo', $riletto['DB_DATABASE']);
        $this->assertArrayHasKey('APP_NAME', $riletto, 'Il contenuto di .env.example è la base');
    }

    public function test_write_env_rifiuta_i_valori_con_a_capo(): void
    {
        file_put_contents($this->env, "APP_NAME=Demo\n");

        try {
            app(InstallerService::class)->writeEnv(['DB_PASSWORD' => "segreta\nAPP_DEBUG=true"]);
            $this->fail('Attesa eccezione');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('a capo', $e->getMessage());
        }

        $this->assertSame("APP_NAME=Demo\n", file_get_contents($this->env), 'Il file non viene toccato');
    }

    public function test_connessione_fallita_risponde_con_un_messaggio_generico_e_logga_il_dettaglio(): void
    {
        $log = Log::spy();

        // Porta 1 su loopback: connessione rifiutata subito, senza rete
        $esito = app(InstallerService::class)->testDatabase([
            'host' => '127.0.0.1', 'port' => 1, 'database' => 'demo', 'username' => 'root', 'password' => 'x',
        ]);

        $this->assertFalse($esito['ok']);
        $this->assertIsString($esito['error']);
        $this->assertStringNotContainsString('SQLSTATE', $esito['error']);
        $this->assertStringContainsString('127.0.0.1:1', $esito['error']);
        $this->assertStringContainsString('log', $esito['error']);
        $log->shouldHaveReceived('error');
    }

    public function test_il_marcatore_scaduto_resta_una_rivendicazione_ma_il_token_non_vale_piu(): void
    {
        $file = $this->cartella.'/in-corso';
        config(['installer.in_progress_file' => $file]);
        $servizio = app(InstallerService::class);

        $token = $servizio->segnaWizardInCorso();
        $this->assertTrue($servizio->wizardRivendicato());
        $this->assertTrue($servizio->wizardInCorso($token));

        touch($file, time() - 3 * 3600);
        $this->assertTrue($servizio->wizardRivendicato(), 'Il file esiste: qualcuno ha avviato il wizard');
        $this->assertFalse($servizio->wizardInCorso($token), 'Il token è scaduto');

        unlink($file);
        $this->assertFalse($servizio->wizardRivendicato());
    }

    public function test_genera_password_backup_solo_se_vuota(): void
    {
        file_put_contents($this->env, "APP_NAME=Demo\nBACKUP_ARCHIVE_PASSWORD=\n");
        $installer = app(InstallerService::class);

        $password = $installer->generaPasswordBackup();

        $this->assertNotNull($password);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $password);
        $this->assertSame($password, Dotenv::parse((string) file_get_contents($this->env))['BACKUP_ARCHIVE_PASSWORD']);
        $this->assertSame($password, config('backup.backup.password'));

        // Una password già presente non si tocca mai: i backup esistenti restano apribili
        $this->assertNull($installer->generaPasswordBackup());
        $this->assertSame($password, Dotenv::parse((string) file_get_contents($this->env))['BACKUP_ARCHIVE_PASSWORD']);
    }

    public function test_password_backup_impostata_a_mano_resta_invariata(): void
    {
        file_put_contents($this->env, "BACKUP_ARCHIVE_PASSWORD=scelta-da-me\n");

        $this->assertNull(app(InstallerService::class)->generaPasswordBackup());
        $this->assertSame('scelta-da-me', Dotenv::parse((string) file_get_contents($this->env))['BACKUP_ARCHIVE_PASSWORD']);
    }

    public function test_password_backup_da_mostrare_una_sola_volta(): void
    {
        file_put_contents($this->env, "BACKUP_ARCHIVE_PASSWORD=Backup-Prova-Generata-42\n");
        $installer = app(InstallerService::class);

        // Senza il segnale del wizard non si mostra nulla (installazioni esistenti)
        $this->assertNull($installer->passwordBackupDaMostrare());

        session()->put(InstallerService::SESSIONE_PASSWORD_BACKUP, true);
        $this->assertSame('Backup-Prova-Generata-42', $installer->passwordBackupDaMostrare());
        $this->assertNull($installer->passwordBackupDaMostrare());
    }
}
