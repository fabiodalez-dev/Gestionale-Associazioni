<?php

namespace App\Support\Sistema;

use Illuminate\Support\Facades\Cache;
use Spatie\FailedJobMonitor\Notification;

/**
 * Filtro degli avvisi sui job falliti (failed-job-monitor.notificationFilter):
 * niente email senza destinatari e al massimo una per classe di job ogni
 * failed-job-monitor.throttle_minutes, così una coda che fallisce in massa
 * (es. SMTP giù) non inonda la casella.
 */
class FiltroJobFalliti
{
    public static function consenti(Notification $notifica): bool
    {
        if (config('failed-job-monitor.mail.to', []) === []) {
            return false;
        }

        $minuti = max(1, (int) config('failed-job-monitor.throttle_minutes', 15));
        $chiave = 'job-falliti:avviso:'.sha1($notifica->getEvent()->job->resolveName());

        return Cache::add($chiave, true, now()->addMinutes($minuti));
    }
}
