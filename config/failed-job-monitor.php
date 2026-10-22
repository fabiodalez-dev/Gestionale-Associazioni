<?php

use App\Notifications\Sistema\DestinatariJobFalliti;
use App\Notifications\Sistema\JobFallito;
use App\Support\Sistema\FiltroJobFalliti;

/*
| Avviso email quando un job in coda fallisce definitivamente
| (spatie/laravel-failed-job-monitor). Destinatari da Impostazioni di
| sistema → Backup: FAILED_JOB_EMAILS (separati da virgola), altrimenti
| BACKUP_NOTIFICATION_MAIL. Al massimo un'email per classe di job ogni
| throttle_minutes; l'elenco completo è nella pagina Job falliti.
*/

return [
    'enabled' => env('FAILED_JOB_MONITOR_ENABLED', true),

    'notification' => JobFallito::class,

    'notifiable' => DestinatariJobFalliti::class,

    'notificationFilter' => [FiltroJobFalliti::class, 'consenti'],

    'channels' => ['mail'],

    'mail' => [
        'to' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FAILED_JOB_EMAILS', (string) env('BACKUP_NOTIFICATION_MAIL', ''))),
        ))),
    ],

    'slack' => [
        'webhook_url' => null,
    ],

    'throttle_minutes' => (int) env('FAILED_JOB_THROTTLE_MINUTES', 15),
];
