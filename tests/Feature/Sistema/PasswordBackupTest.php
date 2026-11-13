<?php

namespace Tests\Feature\Sistema;

use App\Models\Utente;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Password degli archivi di backup dalle Impostazioni di sistema: le
 * installazioni esistenti senza password vedono un avviso e possono
 * generarla; una password già impostata non viene mai sostituita.
 */
class PasswordBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = storage_path('framework/testing/env-password-backup-test');
        config(['installer.env_file' => $this->envFile, 'backup.backup.password' => null]);
        File::ensureDirectoryExists(dirname($this->envFile));
        file_put_contents($this->envFile, "APP_NAME=Demo\nBACKUP_NOTIFICATION_MAIL=admin@example.com\nBACKUP_ARCHIVE_PASSWORD=\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
        File::deleteDirectory(storage_path('app/private/env-backups'));
        parent::tearDown();
    }

    private function superAdmin(): Utente
    {
        return Utente::factory()->superAdmin()->create();
    }

    /** @return array<string, string|null> */
    private function env(): array
    {
        return Dotenv::parse((string) file_get_contents($this->envFile));
    }

    public function test_senza_password_compare_l_avviso_e_l_opzione_per_generarla(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/impostazioni-sistema/backup')
            ->assertOk()
            ->assertSee('I backup non sono cifrati')
            ->assertSee('BACKUP_ARCHIVE_PASSWORD_genera', false);
    }

    public function test_con_password_impostata_niente_avviso(): void
    {
        config(['backup.backup.password' => 'gia-impostata']);
        file_put_contents($this->envFile, "BACKUP_ARCHIVE_PASSWORD=gia-impostata\n");

        $this->actingAs($this->superAdmin())
            ->get('/impostazioni-sistema/backup')
            ->assertOk()
            ->assertDontSee('I backup non sono cifrati')
            ->assertDontSee('BACKUP_ARCHIVE_PASSWORD_genera', false);
    }

    public function test_genera_la_password_e_la_mostra_una_volta(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put('/impostazioni-sistema/backup', [
                'BACKUP_NOTIFICATION_MAIL' => 'admin@example.com',
                'BACKUP_ARCHIVE_PASSWORD' => '',
                'BACKUP_ARCHIVE_PASSWORD_genera' => '1',
            ])
            ->assertRedirect(route('impostazioni-sistema.index', ['sezione' => 'backup']));

        $password = (string) ($this->env()['BACKUP_ARCHIVE_PASSWORD'] ?? '');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $password);

        $this->actingAs($admin)->get('/impostazioni-sistema/backup')->assertOk()->assertSee($password);
        // La seconda visita non la mostra più
        $this->actingAs($admin)->get('/impostazioni-sistema/backup')->assertOk()->assertDontSee($password);
    }

    public function test_generare_non_sostituisce_una_password_gia_presente(): void
    {
        file_put_contents($this->envFile, "BACKUP_ARCHIVE_PASSWORD=gia-impostata\n");

        $this->actingAs($this->superAdmin())
            ->put('/impostazioni-sistema/backup', [
                'BACKUP_ARCHIVE_PASSWORD' => '',
                'BACKUP_ARCHIVE_PASSWORD_genera' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('segreti_generati', []);

        $this->assertSame('gia-impostata', $this->env()['BACKUP_ARCHIVE_PASSWORD']);
    }

    public function test_una_password_scritta_a_mano_vince_sulla_generazione(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/impostazioni-sistema/backup', [
                'BACKUP_ARCHIVE_PASSWORD' => 'Scelta-Da-Me-123',
                'BACKUP_ARCHIVE_PASSWORD_genera' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('Scelta-Da-Me-123', $this->env()['BACKUP_ARCHIVE_PASSWORD']);
    }
}
