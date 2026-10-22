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

    /** Simula un wizard che ha già installato lo schema e creato l'admin */
    private function wizardInCorso(): void
    {
        $this->seed(RolesSeeder::class);
        Utente::factory()->superAdmin()->create();
        app(InstallerService::class)->segnaWizardInCorso();
    }

    public function test_app_non_installata_redirige_tutte_le_rotte_a_install(): void
    {
        $this->get('/login')->assertRedirect('/install');
        $this->get('/dashboard')->assertRedirect('/install');
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
            $mock->shouldReceive('writeEnv')->once()->ordered()->withArgs(fn (array $v) => ($v['DB_DATABASE'] ?? null) === 'demo');
        });

        $this->post('/install/database', [
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'demo', 'username' => 'root', 'password' => '',
        ])->assertRedirect(route('installer.admin'))->assertSessionHas('success');

        $this->assertFileExists($this->inCorsoFile, 'Dopo lo schema il wizard segna che è in corso');
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

        $this->get('/install/done')
            ->assertOk()
            ->assertSee('Installazione completata');

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
                ->andReturn(['ok' => true, 'error' => null, 'tables' => 12]);
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
