<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installer web
    |--------------------------------------------------------------------------
    | Il wizard /install è attivo finché non esiste il file di lock.
    | 'enabled' permette di disattivare del tutto l'installer via env.
    */

    'enabled' => env('INSTALLER_ENABLED', true),

    'lock_file' => env('INSTALLER_LOCK_FILE', storage_path('app/installed.lock')),

    // Marcatore "wizard in corso": creato quando lo schema è stato installato,
    // contiene l'hash di un token che sta solo nel cookie del browser che
    // installa, cancellato dal lock. Senza marcatore e token validi, un
    // database già installato chiude il wizard anche se il lock manca.
    'in_progress_file' => env('INSTALLER_IN_PROGRESS_FILE', storage_path('app/installer-in-corso')),
    'in_progress_ttl_minutes' => 120,
    'in_progress_cookie' => 'installer_token',

    // Versioni minime del server (json nativo e utf8mb4_unicode_ci)
    'min_mysql' => '5.7.8',
    'min_mariadb' => '10.2.7',

    // Cartelle dei file caricati svuotate da "php artisan app:disinstalla"
    'cartelle_file' => [storage_path('app/public'), storage_path('app/media-private')],

    'env_file' => env('INSTALLER_ENV_FILE') ?: base_path('.env'),

    // Schema del database vuoto iniettato dall'installer nel database
    // configurato (generato da `php artisan installer:genera-schema`).
    'schema_file' => env('INSTALLER_SCHEMA_FILE') ?: base_path('database/installer/schema.sql'),

    // Nei test l'enforcement è disattivato di default per non interferire
    // con la suite; i test dell'installer lo abilitano esplicitamente.
    'enforce_in_tests' => false,

    'requirements' => [
        'php' => '8.4.1',
        'extensions' => [
            'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml',
            'ctype', 'json', 'curl', 'fileinfo', 'gd', 'zip', 'intl',
        ],
        'writable' => [
            'storage/framework',
            'storage/logs',
            'storage/app',
            'bootstrap/cache',
        ],
    ],
];
