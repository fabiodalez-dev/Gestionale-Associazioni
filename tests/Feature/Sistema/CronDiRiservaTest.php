<?php

namespace Tests\Feature\Sistema;

use App\Services\Sistema\CronDiRiserva;
use App\Services\Sistema\StatoCron;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Attività programmate eseguite dal web quando il cron manca (#68).
 */
class CronDiRiservaTest extends TestCase
{
    private string $lockFile;

    private int $eseguito = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockFile = sys_get_temp_dir().'/cronriserva_'.uniqid().'.lock';
        file_put_contents($this->lockFile, 'installata');
        config(['installer.lock_file' => $this->lockFile, 'queue.default' => 'sync']);
        Cache::flush();

        $schedule = app(Schedule::class);
        $schedule->call(function (): void {
            $this->eseguito++;
        })->everyFiveMinutes()->name('prova-riserva');
        $schedule->command('health:schedule-check-heartbeat')->everyMinute();
    }

    protected function tearDown(): void
    {
        File::delete($this->lockFile);
        parent::tearDown();
    }

    public function test_senza_cron_esegue_le_attivita_scadute_una_volta(): void
    {
        $this->travelTo(now()->setTime(10, 1));
        $riserva = app(CronDiRiserva::class);

        $this->assertTrue($riserva->necessario());
        $this->assertSame([], $riserva->esegui(), 'Il primo passaggio segna solo il punto di partenza');

        // Passano le 10:05: l'attività ogni 5 minuti è scaduta
        $this->travelTo(now()->setTime(10, 7));
        $this->assertContains('prova-riserva', $riserva->esegui());
        $this->assertSame(1, $this->eseguito);

        // Stesso intervallo: non riparte
        $this->travelTo(now()->setTime(10, 8));
        $riserva->esegui();
        $this->assertSame(1, $this->eseguito);

        // Ore di silenzio: una sola volta, non una per ogni occorrenza persa
        $this->travelTo(now()->setTime(14, 0));
        $riserva->esegui();
        $this->assertSame(2, $this->eseguito);
    }

    public function test_i_comandi_artisan_girano_nello_stesso_processo(): void
    {
        app(Schedule::class)->command('inspire')->hourly();
        $this->travelTo(now()->setTime(8, 30));
        $riserva = app(CronDiRiserva::class);
        $riserva->esegui();

        $this->travelTo(now()->setTime(9, 5));
        $this->assertContains('inspire', $riserva->esegui());
    }

    public function test_non_scrive_mai_il_battito_del_cron(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $riserva = app(CronDiRiserva::class);
        $riserva->esegui();
        $this->travelTo(now()->setTime(9, 30));
        $riserva->esegui();

        $this->assertNull(app(StatoCron::class)->ultimoBattito(), 'Il cron deve continuare a risultare fermo');
        $this->assertTrue($riserva->necessario());
    }

    public function test_con_il_cron_attivo_non_serve(): void
    {
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->subMinute()->getTimestamp());

        $this->assertFalse(app(CronDiRiserva::class)->necessario());
    }

    public function test_app_non_installata_non_serve(): void
    {
        File::delete($this->lockFile);

        $this->assertFalse(app(CronDiRiserva::class)->necessario());
    }

    public function test_dal_web_al_piu_un_turno_al_minuto_dopo_la_risposta(): void
    {
        $this->travelTo(now()->setTime(11, 0));
        $riserva = app(CronDiRiserva::class);
        $riserva->esegui(); // punto di partenza
        $this->travelTo(now()->setTime(11, 6));

        $riserva->pianifica();
        $riserva->pianifica();
        $this->assertSame(0, $this->eseguito, 'Niente durante la richiesta');

        $this->app->terminate();
        $this->assertSame(1, $this->eseguito);
    }
}
