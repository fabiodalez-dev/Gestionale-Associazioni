<?php

namespace Tests\Feature\Installer;

use App\Models\Utente;
use App\Services\Installer\InstallerService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $lockFile;

    private string $inCorsoFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockFile = sys_get_temp_dir().'/installer_test_'.uniqid().'.lock';
        $this->inCorsoFile = sys_get_temp_dir().'/installer_test_'.uniqid().'.in-corso';
        config([
            'installer.enforce_in_tests' => true,
            'installer.lock_file' => $this->lockFile,
            'installer.in_progress_file' => $this->inCorsoFile,
        ]);

        // Il .env di prova (INSTALLER_ENV_FILE in phpunit.xml) deve esistere:
        // altrimenti il middleware lo crea al primo passaggio e risponde con
        // un redirect alla stessa pagina, e il primo test della suite su una
        // macchina pulita fallirebbe. Qui serve un file già presente.
        $env = (string) config('installer.env_file');
        if (! file_exists($env)) {
            File::ensureDirectoryExists(dirname($env));
            File::copy(base_path('.env.example'), $env);
        }
    }

    protected function tearDown(): void
    {
        // Rimuove solo i file creati da questo test nella dir temporanea
        foreach ([$this->lockFile, $this->inCorsoFile] as $file) {
            if (str_starts_with($file, sys_get_temp_dir().'/installer_test_')) {
                File::delete($file);
            }
        }

        parent::tearDown();
    }

    /** Simula un wizard che ha già installato lo schema e creato l'admin, dallo stesso browser */
    private function wizardInCorso(): void
    {
        $this->seed(RolesSeeder::class);
        Utente::factory()->superAdmin()->create();
        $token = app(InstallerService::class)->segnaWizardInCorso();
        $this->withUnencryptedCookie('installer_token', $token);
    }

    public function test_app_non_installata_redirige_tutte_le_rotte_a_install(): void
    {
        $this->get('/login')->assertRedirect('/install');
        $this->get('/dashboard')->assertRedirect('/install');
    }

    public function test_primo_avvio_senza_env_lo_crea_da_env_example_con_la_chiave(): void
    {
        $cartella = sys_get_temp_dir().'/installer_test_'.uniqid();
        mkdir($cartella);
        $env = $cartella.'/.env';
        config(['installer.env_file' => $env, 'app.key' => '']);

        try {
            // Primo passaggio: crea il .env e ricomincia la richiesta
            $this->get('/install')->assertRedirect();
            $this->get('/install')->assertOk();

            $this->assertFileExists($env);
            $contenuto = (string) file_get_contents($env);
            $this->assertStringContainsString('DB_CONNECTION=mysql', $contenuto, 'Parte da .env.example');
            $this->assertMatchesRegularExpression('/^APP_KEY="?base64:[A-Za-z0-9+\/=]+"?$/m', $contenuto);
            // Creato dal wizard = sito in produzione, non la copia di sviluppo
            $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $contenuto);
            $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $contenuto);
            $this->assertMatchesRegularExpression('/^LOG_LEVEL=warning$/m', $contenuto);
            $this->assertStringStartsWith('base64:', (string) config('app.key'), 'La chiave vale già in questa richiesta');
        } finally {
            File::deleteDirectory($cartella);
        }
    }

    public function test_primo_avvio_senza_env_redirige_alla_stessa_pagina_dopo_averlo_creato(): void
    {
        $cartella = sys_get_temp_dir().'/installer_test_'.uniqid();
        mkdir($cartella);
        config(['installer.env_file' => $cartella.'/.env']);

        try {
            $this->get('/install/database?x=1')->assertRedirectContains('/install/database?x=1');
            $this->assertFileExists($cartella.'/.env');
            // Al secondo passaggio il .env c'è: la pagina si apre
            $this->get('/install/database')->assertOk();
        } finally {
            File::deleteDirectory($cartella);
        }
    }

    public function test_schema_installato_da_un_altro_browser_blocca_gli_step_successivi(): void
    {
        $this->seed(RolesSeeder::class); // schema pronto (migrations + utenti), nessun admin
        $token = app(InstallerService::class)->segnaWizardInCorso();

        // Altro browser: niente admin né database, solo la pagina requisiti
        $this->get('/install')->assertOk();
        $this->get('/install/admin')->assertForbidden();
        $this->post('/install/admin', ['nome' => 'x'])->assertForbidden();
        $this->get('/install/database')->assertForbidden();

        // Browser giusto: prosegue
        $this->withUnencryptedCookie('installer_token', $token)->get('/install/admin')->assertOk();

        // Marcatore scaduto: nessuno lo ha più rivendicato, si può riprendere
        touch($this->inCorsoFile, time() - 3 * 3600);
        $this->get('/install/admin')->assertOk();
    }

    public function test_pagina_requisiti_raggiungibile_quando_non_installata(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('Requisiti di sistema');
    }

    public function test_installer_bloccato_quando_gia_installata(): void
    {
        file_put_contents($this->lockFile, 'test');

        $this->get('/install')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_step_database_mostra_il_form(): void
    {
        $this->get('/install/database')
            ->assertOk()
            ->assertSee('Configurazione database')
            ->assertSee('name="host"', false);
    }

    public function test_database_store_con_connessione_fallita_mostra_errore(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->once()
                ->andReturn(['ok' => false, 'error' => 'Access denied', 'tables' => 0]);
            $mock->shouldReceive('writeEnv')->never();
            $mock->shouldReceive('installaSchema')->never();
        });

        $this->from('/install/database')
            ->post('/install/database', [
                'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'demo', 'username' => 'root', 'password' => 'wrong',
            ])
            ->assertRedirect('/install/database')
            ->assertSessionHasErrors('database');
    }

    public function test_database_store_valido_installa_lo_schema_e_solo_dopo_scrive_env(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->once()->andReturn(['ok' => true, 'error' => null, 'tables' => 0, 'tabelle' => [], 'installata' => false, 'server' => '8.0.36']);
            $mock->shouldReceive('installaSchema')->once()->ordered()->andReturn(['tabelle' => 94, 'migrazioni' => 66]);
            $mock->shouldReceive('writeEnv')->once()->ordered()->withArgs(fn (array $v) => ($v['DB_DATABASE'] ?? null) === 'demo'
                && ($v['APP_URL'] ?? null) === 'http://localhost/gestionale/public');
        });

        // App servita da una sottocartella (hosting condiviso): APP_URL la include
        $this->call('POST', 'http://localhost/gestionale/public/install/database', [
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'demo', 'username' => 'root', 'password' => '',
        ], [], [], ['SCRIPT_NAME' => '/gestionale/public/index.php', 'SCRIPT_FILENAME' => '/var/www/gestionale/public/index.php', 'PHP_SELF' => '/gestionale/public/index.php'])
            ->assertRedirect(route('installer.admin'))->assertSessionHas('success')->assertCookie('installer_token');

        $this->assertFileExists($this->inCorsoFile, 'Dopo lo schema il wizard segna che è in corso');
    }

    public function test_wizard_in_corso_resta_aperto_solo_al_browser_che_lo_sta_facendo(): void
    {
        $this->seed(RolesSeeder::class);
        Utente::factory()->superAdmin()->create();
        $token = app(InstallerService::class)->segnaWizardInCorso();

        // Altro browser (nessun token o token sbagliato): wizard chiuso, lock scritto
        $this->get('/install/email')->assertRedirect('/login');
        $this->assertFileExists($this->lockFile);
        File::delete($this->lockFile);
        app(InstallerService::class)->segnaWizardInCorso(); // nuovo marcatore, il token vecchio non vale più
        $this->withUnencryptedCookie('installer_token', $token)->get('/install/email')->assertRedirect('/login');
        File::delete($this->lockFile);

        // Browser giusto: prosegue, ma lo step database non è più raggiungibile
        $token = app(InstallerService::class)->segnaWizardInCorso();
        $this->withUnencryptedCookie('installer_token', $token)->get('/install/email')->assertOk();
        $this->withUnencryptedCookie('installer_token', $token)->get('/install/database')->assertRedirect(route('installer.email'));
        $this->withUnencryptedCookie('installer_token', $token)->post('/install/database', [
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'demo', 'username' => 'root',
        ])->assertRedirect(route('installer.email'));
        $this->assertFileDoesNotExist($this->lockFile);

        // Marcatore scaduto: chiuso anche con il token giusto
        touch($this->inCorsoFile, time() - 3 * 3600);
        $this->withUnencryptedCookie('installer_token', $token)->get('/install/email')->assertRedirect('/login');
    }

    public function test_import_schema_fallito_mostra_l_errore_e_non_tocca_env(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->once()->andReturn(['ok' => true, 'error' => null, 'tables' => 0, 'tabelle' => [], 'installata' => false, 'server' => '8.0.36']);
            $mock->shouldReceive('installaSchema')->once()->andThrow(new \RuntimeException('Errore nell\'istruzione 12 di 200 dello schema (CREATE TABLE `x`): Access denied'));
            $mock->shouldReceive('writeEnv')->never();
        });

        $this->from('/install/database')
            ->post('/install/database', [
                'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'demo', 'username' => 'root', 'password' => '',
            ])
            ->assertRedirect('/install/database')
            ->assertSessionHasErrors('database');

        $this->assertStringContainsString('istruzione 12 di 200', session('errors')->first('database'));
        $this->assertFileDoesNotExist($this->inCorsoFile);
    }

    public function test_database_non_vuoto_elenca_le_tabelle_dell_app_che_verrebbero_cancellate(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->once()
                ->andReturn(['ok' => true, 'error' => null, 'tables' => 3, 'tabelle' => ['utenti', 'wp_posts', 'soci'], 'installata' => false, 'server' => '8.0.36']);
            $mock->shouldReceive('installaSchema')->never();
        });

        $this->from('/install/database')
            ->post('/install/database', [
                'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'demo', 'username' => 'root', 'password' => '',
            ])
            ->assertRedirect('/install/database')
            ->assertInvalid(['database' => 'soci, utenti']);

        $this->assertStringNotContainsString('wp_posts', json_encode(session('errors')));
    }

    public function test_step_admin_crea_super_admin_e_associazione(): void
    {
        $this->seed(RolesSeeder::class);

        $this->post('/install/admin', [
            'associazione_nome' => 'APS Demo',
            'associazione_email' => 'info@apsdemo.it',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario@apsdemo.it',
            'password' => 'SuperSegreta123',
            'password_confirmation' => 'SuperSegreta123',
        ])->assertRedirect(route('installer.email'));

        $admin = Utente::query()->where('email', 'mario@apsdemo.it')->firstOrFail();
        $this->assertSame('super_admin', $admin->ruolo);
        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertDatabaseHas('associazioni', ['nome' => 'APS Demo', 'email' => 'info@apsdemo.it']);
    }

    public function test_step_done_scrive_il_lock(): void
    {
        $this->wizardInCorso();

        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('collegaStorage')->once()->andReturnFalse();
        });

        $this->get('/install/done')
            ->assertOk()
            ->assertSee('Installazione completata')
            ->assertSee('Cron Jobs')
            ->assertSee('storage:link');

        $this->assertFileExists($this->lockFile);
        $this->assertFileDoesNotExist($this->inCorsoFile);
    }

    public function test_installazione_manuale_da_cli_viene_riconosciuta_e_crea_il_lock(): void
    {
        $this->seed(RolesSeeder::class);
        Utente::factory()->superAdmin()->create();

        // Nessun lock, ma il super admin esiste: il middleware riconosce
        // l'installazione manuale e scrive il lock senza passare dal wizard
        $this->get('/login')->assertOk();
        $this->assertFileExists($this->lockFile);
    }

    public function test_password_debole_viene_rifiutata(): void
    {
        $this->post('/install/admin', [
            'associazione_nome' => 'APS Demo',
            'associazione_email' => 'info@apsdemo.it',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario@apsdemo.it',
            'password' => 'corta1',
            'password_confirmation' => 'corta1',
        ])->assertSessionHasErrors('password');
    }

    public function test_database_non_vuoto_richiede_conferma_esplicita(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->twice()
                ->andReturn(['ok' => true, 'error' => null, 'tables' => 12, 'tabelle' => ['wp_posts', 'wp_users'], 'installata' => false, 'server' => '8.0.36']);
            $mock->shouldReceive('writeEnv')->once();
            $mock->shouldReceive('installaSchema')->once()->andReturn(['tabelle' => 94, 'migrazioni' => 66]);
        });

        $payload = [
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'demo', 'username' => 'root', 'password' => '',
        ];

        // Senza conferma: errore
        $this->from('/install/database')
            ->post('/install/database', $payload)
            ->assertRedirect('/install/database')
            ->assertSessionHasErrors('database');

        // Con conferma esplicita: procede
        $this->post('/install/database', $payload + ['consenti_non_vuoto' => 1])
            ->assertRedirect(route('installer.admin'));
    }

    public function test_database_gia_installato_non_viene_mai_sovrascritto_dal_wizard(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->once()
                ->andReturn(['ok' => true, 'error' => null, 'tables' => 94, 'installata' => true]);
            $mock->shouldReceive('writeEnv')->never();
            $mock->shouldReceive('installaSchema')->never();
        });

        // Nemmeno con la conferma di sovrascrittura
        $this->from('/install/database')
            ->post('/install/database', [
                'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'demo', 'username' => 'root', 'password' => '',
                'consenti_non_vuoto' => 1,
            ])
            ->assertRedirect('/install/database')
            ->assertSessionHasErrors('database');

        $this->assertStringContainsString('app:disinstalla', session('errors')->first('database'));
    }

    public function test_lock_assente_ma_app_installata_chiude_il_wizard(): void
    {
        $this->seed(RolesSeeder::class);
        Utente::factory()->superAdmin()->create();

        $this->get('/install')->assertRedirect('/login');
        $this->assertFileExists($this->lockFile);

        $this->post('/install/database', [
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'demo', 'username' => 'root', 'password' => '',
        ])->assertRedirect('/login');
    }

    public function test_endpoint_ajax_test_connessione(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->once()
                ->andReturn(['ok' => true, 'error' => null, 'tables' => 3, 'installata' => false]);
        });

        $this->postJson('/install/database/test', [
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'demo', 'username' => 'root', 'password' => '',
        ])->assertOk()->assertJson(['ok' => true, 'tables' => 3, 'installata' => false]);
    }

    public function test_dati_demo_opzionali_creano_i_contenuti(): void
    {
        $this->seed(RolesSeeder::class);

        $this->post('/install/admin', [
            'associazione_nome' => 'APS Reale',
            'associazione_email' => 'info@apsreale.it',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario@apsreale.it',
            'password' => 'SuperSegreta123',
            'password_confirmation' => 'SuperSegreta123',
            'dati_demo' => 1,
        ])->assertRedirect(route('installer.email'));

        $this->assertDatabaseHas('associazioni', ['nome' => 'Associazione Demo']);
        $this->assertDatabaseHas('soci', ['cognome' => 'Verdi', 'email' => 'anna.verdi@demo.test']);
        $this->assertDatabaseHas('eventi', ['titolo' => 'Assemblea annuale']);
        // Nessun account utente demo
        $this->assertSame(1, Utente::query()->count());
    }

    public function test_step_email_puo_essere_saltato(): void
    {
        $this->wizardInCorso();

        $this->post('/install/email', ['driver' => 'log'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('installer.done'));
    }

    public function test_step_email_salva_le_impostazioni_smtp(): void
    {
        $this->wizardInCorso();

        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('writeEnv')->once()->withArgs(function (array $valori) {
                return ($valori['MAIL_MAILER'] ?? null) === 'smtp'
                    && ($valori['MAIL_HOST'] ?? null) === 'smtp.example.com';
            });
        });

        $response = $this->post('/install/email', [
            'driver' => 'smtp',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Demo',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('installer.done'));
    }
}
