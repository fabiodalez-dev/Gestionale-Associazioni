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

    // Cartelle dei file caricati svuotate da "php artisan app:disinstalla"
    'cartelle_file' => [storage_path('app/public'), storage_path('app/media-private')],

    'env_file' => env('INSTALLER_ENV_FILE') ?: base_path('.env'),

    // Nei test l'enforcement è disattivato di default per non interferire
    // con la suite; i test dell'installer lo abilitano esplicitamente.
    'enforce_in_tests' => false,

    'requirements' => [
        'php' => '8.3.0',
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
