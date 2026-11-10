<?php

namespace App\Services\Sistema;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

/**
 * Il cron gira? Lo dice il battito che lo scheduler scrive ogni minuto
 * (health:schedule-check-heartbeat, lo stesso letto dallo ScheduleCheck del
 * controllo di salute). Senza cron restano ferme email in coda, promemoria,
 * scadenze e backup: l'amministratore della piattaforma lo deve sapere senza
 * andare a cercarlo.
 */
class StatoCron
{
    /** Oltre questo silenzio il cron si considera fermo. */
    public const ORE_MASSIME = 24;

    public function ultimoBattito(): ?CarbonImmutable
    {
        $check = $this->scheduleCheck();
        $chiave = $check?->getCacheKey() ?? 'health:checks:schedule:latestHeartbeatAt';

        try {
            $valore = Cache::store($check?->getCacheStoreName())->get($chiave);
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($valore) ? CarbonImmutable::createFromTimestamp((int) $valore) : null;
    }

    /**
     * Fermo se l'ultimo battito è più vecchio di ORE_MASSIME, oppure se non
     * c'è mai stato e l'app è installata da più di ORE_MASSIME (il primo
     * giorno dopo l'installazione si lascia il tempo di configurarlo).
     */
    public function fermo(): bool
    {
        $limite = now()->subHours(self::ORE_MASSIME);

        $battito = $this->ultimoBattito();
        if ($battito !== null) {
            return $battito->lt($limite);
        }

        $installataIl = $this->installataIl();

        return $installataIl !== null && $installataIl->lt($limite);
    }

    /** Il comando da incollare nel pannello dell'hosting, con il percorso reale dell'app. */
    public function comando(): string
    {
        return 'cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1 && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1';
    }

    private function installataIl(): ?CarbonImmutable
    {
        $lock = (string) config('installer.lock_file');
        $mtime = is_file($lock) ? @filemtime($lock) : false;

        return $mtime === false ? null : CarbonImmutable::createFromTimestamp($mtime);
    }

    private function scheduleCheck(): ?ScheduleCheck
    {
        foreach (Health::registeredChecks() as $check) {
            if ($check instanceof ScheduleCheck) {
                return $check;
            }
        }

        return null;
    }
}
