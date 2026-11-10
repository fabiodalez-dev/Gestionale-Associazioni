<?php

namespace Tests\Feature\Sistema;

use App\Models\Associazione;
use App\Models\Utente;
use App\Services\Sistema\StatoCron;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StatoCronTest extends TestCase
{
    use RefreshDatabase;

    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockFile = sys_get_temp_dir().'/statocron_'.uniqid().'.lock';
        config(['installer.lock_file' => $this->lockFile]);
        Cache::forget('health:checks:schedule:latestHeartbeatAt');
    }

    protected function tearDown(): void
    {
        File::delete($this->lockFile);
        parent::tearDown();
    }

    private function battito(int $oreFa): void
    {
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->subHours($oreFa)->timestamp);
    }

    private function installataDa(int $ore): void
    {
        file_put_contents($this->lockFile, 'installata');
        touch($this->lockFile, now()->subHours($ore)->timestamp);
    }

    public function test_battito_recente_cron_attivo(): void
    {
        $this->installataDa(100);
        $this->battito(1);

        $this->assertFalse(app(StatoCron::class)->fermo());
    }

    public function test_battito_piu_vecchio_di_un_giorno_cron_fermo(): void
    {
        $this->installataDa(100);
        $this->battito(25);

        $this->assertTrue(app(StatoCron::class)->fermo());
    }

    public function test_mai_partito_dopo_un_giorno_dall_installazione_cron_fermo(): void
    {
        $this->installataDa(25);

        $this->assertTrue(app(StatoCron::class)->fermo());
    }

    public function test_mai_partito_ma_installata_da_poco_si_aspetta(): void
    {
        $this->installataDa(2);

        $this->assertFalse(app(StatoCron::class)->fermo());
    }

    public function test_senza_lock_e_senza_battito_nessun_falso_allarme(): void
    {
        $this->assertFalse(app(StatoCron::class)->fermo());
    }

    public function test_il_comando_contiene_il_percorso_reale_dell_app(): void
    {
        $this->assertStringContainsString('cd '.base_path().' && php artisan schedule:run', app(StatoCron::class)->comando());
    }

    public function test_avviso_in_dashboard_solo_al_super_admin_e_solo_se_fermo(): void
    {
        $this->installataDa(100);
        $this->battito(30);
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-testid="avviso-cron-fermo"', false)
            ->assertSee(base_path());

        // Admin di un'associazione: il cron non è affar suo
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->create(['associazione_id' => $associazione->id]);
        $this->actingAs($admin)->get('/dashboard')
            ->assertDontSee('data-testid="avviso-cron-fermo"', false);

        // Il cron riparte: l'avviso sparisce da solo
        $this->battito(0);
        $this->actingAs($superAdmin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-testid="avviso-cron-fermo"', false);
    }
}
