<?php

use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

/*
|--------------------------------------------------------------------------
| Health check (spatie/laravel-health) — miglioramento n. 20
|--------------------------------------------------------------------------
|
| I check registrati sono in App\Providers\AppServiceProvider::boot():
| database, coda, scheduler, spazio disco, debug mode (in produzione).
| Esecuzione: health:check schedulato in routes/console.php.
| Consultazione: rotta /health protetta (routes/modules/operations.php).
|
*/

return [
    /*
     * I risultati dei check vengono salvati nel database
     * (tabella health_check_result_history_items, migration già presente).
     */
    'result_stores' => [
        EloquentHealthResultStore::class => [
            'connection' => env('HEALTH_DB_CONNECTION', env('DB_CONNECTION')),
            'model' => HealthCheckResultHistoryItem::class,
            'keep_history_for_days' => 7,
        ],
    ],

    /*
     * Notifica email quando un check fallisce (throttle: max 1/ora).
     */
    'notifications' => [
        'enabled' => env('HEALTH_NOTIFICATIONS_ENABLED', true),

        'notifications' => [
            CheckFailedNotification::class => ['mail'],
        ],

        'notifiable' => Notifiable::class,

        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        /*
         * Notifica solo sui fallimenti veri (i warning restano visibili
         * nella pagina /health senza generare email).
         */
        'only_on_failure' => true,

        'mail' => [
            'to' => env('HEALTH_TO_ADDRESS', env('BACKUP_NOTIFICATION_MAIL', 'admin@example.com')),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Gestione Associazioni')),
            ],
        ],

        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL', ''),
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],
    ],

    /*
     * Endpoint Oh Dear (non usato).
     */
    'oh_dear_endpoint' => [
        'enabled' => false,
        'always_send_fresh_results' => true,
        'secret' => env('OH_DEAR_HEALTH_CHECK_SECRET'),
        'url' => '/oh-dear-health-check-results',
    ],

    /*
     * URL heartbeat per l'HorizonCheck (non usato).
     */
    'horizon' => [
        'heartbeat_url' => env('HORIZON_HEARTBEAT_URL'),
    ],

    /*
     * URL heartbeat opzionale pingato quando lo ScheduleCheck è ok.
     */
    'schedule' => [
        'heartbeat_url' => env('SCHEDULE_HEARTBEAT_URL'),
    ],

    /*
     * Tema della pagina HTML /health.
     */
    'theme' => 'light',

    /*
     * Nasconde gli HealthQueueJob completati nella schermata di Horizon.
     */
    'silence_health_queue_job' => true,

    /*
     * Status HTTP della risposta JSON quando un check fallisce
     * (200 per non far scattare falsi allarmi nei monitor di uptime:
     * lo stato si legge dal payload).
     */
    'json_results_failure_status' => 200,

    /*
     * Token segreto opzionale (header X-Secret-Token) per accesso da
     * sistemi di uptime monitoring esterni senza sessione.
     */
    'secret_token' => env('HEALTH_SECRET_TOKEN'),
];
