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

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockFile = sys_get_temp_dir().'/installer_test_'.uniqid().'.lock';
        config([
            'installer.enforce_in_tests' => true,
            'installer.lock_file' => $this->lockFile,
        ]);
    }

    protected function tearDown(): void
    {
        // Rimuove solo il lock creato da questo test nella dir temporanea
        if (str_starts_with($this->lockFile, sys_get_temp_dir().'/installer_test_')) {
            File::delete($this->lockFile);
        }

        parent::tearDown();
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
                ->andReturn(['ok' => false, 'error' => 'Access denied']);
            $mock->shouldReceive('writeEnv')->never();
            $mock->shouldReceive('runMigrations')->never();
        });

        $this->from('/install/database')
            ->post('/install/database', [
                'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'demo', 'username' => 'root', 'password' => 'wrong',
            ])
            ->assertRedirect('/install/database')
            ->assertSessionHasErrors('database');
    }

    public function test_database_store_valido_scrive_env_e_migra(): void
    {
        $this->partialMock(InstallerService::class, function ($mock) {
            $mock->shouldReceive('testDatabase')->once()->andReturn(['ok' => true, 'error' => null]);
            $mock->shouldReceive('writeEnv')->once();
            $mock->shouldReceive('runMigrations')->once();
        });

        $this->post('/install/database', [
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'demo', 'username' => 'root', 'password' => '',
        ])->assertRedirect(route('installer.admin'));
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
        ])->assertRedirect(route('installer.done'));

        $admin = Utente::query()->where('email', 'mario@apsdemo.it')->firstOrFail();
        $this->assertSame('super_admin', $admin->ruolo);
        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertDatabaseHas('associazioni', ['nome' => 'APS Demo', 'email' => 'info@apsdemo.it']);
    }

    public function test_step_done_scrive_il_lock(): void
    {
        $this->seed(RolesSeeder::class);
        Utente::factory()->superAdmin()->create();

        $this->get('/install/done')
            ->assertOk()
            ->assertSee('Installazione completata');

        $this->assertFileExists($this->lockFile);
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
}
