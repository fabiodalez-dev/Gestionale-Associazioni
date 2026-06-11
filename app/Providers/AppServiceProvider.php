<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerHealthChecks();
    }

    /**
     * Health check applicativi (miglioramento n. 20).
     *
     * Eseguiti da health:check (schedulato in routes/console.php) e
     * consultabili sulla rotta protetta /health:
     * - database raggiungibile;
     * - coda attiva (heartbeat: health:queue-check-heartbeat);
     * - scheduler attivo (heartbeat: health:schedule-check-heartbeat);
     * - spazio disco usato (warning oltre 70%, errore oltre 90%);
     * - debug mode spento — registrato solo in produzione, dove
     *   APP_DEBUG=true sarebbe un problema di sicurezza.
     */
    private function registerHealthChecks(): void
    {
        $checks = [
            DatabaseCheck::new(),
            QueueCheck::new()->failWhenHealthJobTakesLongerThanMinutes(10),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(5),
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(90),
        ];

        if ($this->app->environment('production')) {
            $checks[] = DebugModeCheck::new();
        }

        Health::checks($checks);
    }
}
