<?php

namespace Tests\Feature\Admin;

use App\Models\Associazione;
use App\Models\Utente;
use App\Services\Sistema\EnvEditor;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImpostazioniSistemaTest extends TestCase
{
    use RefreshDatabase;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = storage_path('framework/testing/env-impostazioni-test');
        config(['installer.env_file' => $this->envFile]);
        File::ensureDirectoryExists(dirname($this->envFile));
        file_put_contents($this->envFile, implode("\n", [
            '# Commento da conservare',
            'APP_NAME="Gestione Associazioni"',
            'APP_KEY=base64:chiave-segreta',
            'APP_URL=http://localhost',
            'MAIL_MAILER=log',
            'MAIL_PASSWORD=vecchia-password',
            'VARIABILE_CUSTOM=valore',
        ])."\n");
        File::deleteDirectory(storage_path('app/private/env-backups'));
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

    public function test_solo_il_super_admin_accede(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => Associazione::factory()->create()->id]);

        $this->actingAs($admin)->get('/impostazioni-sistema')->assertForbidden();
        $this->actingAs($admin)->put('/impostazioni-sistema/generale', ['APP_NAME' => 'X'])->assertForbidden();
        $this->post('/logout');
        $this->get('/impostazioni-sistema')->assertRedirect('/login');
    }

    public function test_tutte_le_sezioni_si_aprono(): void
    {
        $this->actingAs($this->superAdmin());

        foreach (['generale' => 'URL pubblico', 'email' => 'Host SMTP', 'sicurezza' => 'Robustezza minima', 'database' => 'Nome database', 'backup' => 'Token GitHub', 'sistema' => 'VARIABILE_CUSTOM'] as $sezione => $testo) {
            $this->get('/impostazioni-sistema/'.$sezione)->assertOk()->assertSee($testo);
        }
        // I valori segreti non vengono mai mostrati
        $this->get('/impostazioni-sistema/email')->assertDontSee('vecchia-password');
        $this->get('/impostazioni-sistema/sistema')->assertDontSee('chiave-segreta');
    }

    public function test_salvataggio_generale_preserva_il_resto_e_crea_backup(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/impostazioni-sistema/generale', [
                'APP_NAME' => 'Il Mio Gestionale',
                'APP_URL' => 'https://gestionale.example.com/',
                'APP_ENV' => 'production',
                'APP_DEBUG' => '0',
                'APP_LOCALE' => 'it',
                'LOG_LEVEL' => 'warning',
            ])
            ->assertRedirect(route('impostazioni-sistema.index', ['sezione' => 'generale']))
            ->assertSessionHas('success');

        $env = $this->env();
        $this->assertSame('Il Mio Gestionale', $env['APP_NAME']);
        $this->assertSame('https://gestionale.example.com', $env['APP_URL']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('base64:chiave-segreta', $env['APP_KEY']);
        $this->assertSame('valore', $env['VARIABILE_CUSTOM']);
        $this->assertStringContainsString('# Commento da conservare', (string) file_get_contents($this->envFile));
        $this->assertSame(1, app(EnvEditor::class)->backupCount());
    }

    public function test_validazione_rifiuta_valori_non_validi(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/impostazioni-sistema/generale', [
                'APP_NAME' => 'X', 'APP_URL' => 'ftp://nope', 'APP_ENV' => 'hacker', 'APP_LOCALE' => 'it', 'LOG_LEVEL' => 'debug',
            ])
            ->assertSessionHasErrors(['APP_URL', 'APP_ENV']);

        $this->assertSame('http://localhost', $this->env()['APP_URL']);
    }

    public function test_password_smtp_vuota_resta_invariata_e_valori_speciali_sopravvivono(): void
    {
        $dati = [
            'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.com', 'MAIL_PORT' => '587', 'MAIL_SCHEME' => '',
            'MAIL_USERNAME' => 'utente@example.com', 'MAIL_PASSWORD' => '', 'MAIL_FROM_ADDRESS' => 'noreply@example.com',
            'MAIL_FROM_NAME' => "Associazione L'Ulivo #1",
        ];
        $this->actingAs($this->superAdmin());

        $this->put('/impostazioni-sistema/email', $dati)->assertSessionHas('success');
        $this->assertSame('vecchia-password', $this->env()['MAIL_PASSWORD']);
        $this->assertSame("Associazione L'Ulivo #1", $this->env()['MAIL_FROM_NAME']);

        $this->put('/impostazioni-sistema/email', ['MAIL_PASSWORD' => 'p@ss "con" $spazi e #'] + $dati)->assertSessionHas('success');
        $this->assertSame('p@ss "con" $spazi e #', $this->env()['MAIL_PASSWORD']);

        $this->put('/impostazioni-sistema/email', ['MAIL_PASSWORD_rimuovi' => '1'] + $dati)->assertSessionHas('success');
        $this->assertSame('', $this->env()['MAIL_PASSWORD']);
    }

    public function test_email_di_prova_con_mailer_log(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/impostazioni-sistema/email/test', [
                'MAIL_MAILER' => 'log', 'MAIL_HOST' => '', 'MAIL_PORT' => '587', 'MAIL_SCHEME' => '',
                'MAIL_FROM_ADDRESS' => 'noreply@example.com', 'MAIL_FROM_NAME' => 'Test', 'test_destinatario' => 'dest@example.com',
            ])
            ->assertSessionHas('success');

        // Il test non salva nulla
        $this->assertSame('log', $this->env()['MAIL_MAILER']);
        $this->assertArrayNotHasKey('MAIL_FROM_ADDRESS', $this->env());
    }

    public function test_database_non_valido_non_viene_salvato(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/impostazioni-sistema/database', [
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'inesistente', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => 'x',
            ])
            ->assertSessionHas('error');

        $this->assertArrayNotHasKey('DB_DATABASE', $this->env());
    }

    public function test_sicurezza_aggiorna_soglie_usate_dall_app(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/impostazioni-sistema/sicurezza', [
                'AUTH_LOGIN_ATTEMPTS' => '10', 'AUTH_LOCKOUT_MINUTES' => '15', 'AUTH_REMEMBER_DAYS' => '14', 'AUTH_RESET_MINUTES' => '30',
                'ZXCVBN_MIN_SCORE' => '4', 'SESSION_LIFETIME' => '60', 'SESSION_ENCRYPT' => '1',
                'HONEYPOT_ENABLED' => '1', 'CSP_ENABLED' => '1', 'ACTIVITYLOG_ENABLED' => '0',
            ])
            ->assertSessionHas('success');

        $env = $this->env();
        $this->assertSame('14', $env['AUTH_REMEMBER_DAYS']);
        $this->assertSame('15', $env['AUTH_LOCKOUT_MINUTES']);
        $this->assertSame('4', $env['ZXCVBN_MIN_SCORE']);
        $this->assertSame('true', $env['SESSION_ENCRYPT']);
        $this->assertSame('false', $env['ACTIVITYLOG_ENABLED']);
    }
}
