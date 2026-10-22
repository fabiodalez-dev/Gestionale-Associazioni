<?php

use App\Models\Account;
use App\Models\Socio;
use App\Models\Utente;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | Guard di default: 'web' → tabella `utenti` (account amministrativi).
    | La guard 'socio' serve l'area soci (tabella `soci`).
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'utenti'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'utenti',
            // Durata del cookie "Ricordami" in minuti (AUTH_REMEMBER_DAYS giorni)
            'remember' => (int) env('AUTH_REMEMBER_DAYS', 30) * 1440,
        ],

        'socio' => [
            'driver' => 'session',
            'provider' => 'soci',
        ],

        // Frontend pubblico prenotazioni: account ospite (tabella accounts)
        'account' => [
            'driver' => 'session',
            'provider' => 'accounts',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'utenti' => [
            'driver' => 'eloquent',
            'model' => Utente::class,
        ],

        'soci' => [
            'driver' => 'eloquent',
            'model' => Socio::class,
        ],

        'accounts' => [
            'driver' => 'eloquent',
            'model' => Account::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'utenti' => [
            'provider' => 'utenti',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => (int) env('AUTH_RESET_MINUTES', 60),
            'throttle' => 60,
        ],

        'soci' => [
            'provider' => 'soci',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        'accounts' => [
            'provider' => 'accounts',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    // Tentativi di login al minuto per email+IP (FortifyServiceProvider)
    'login_max_attempts' => (int) env('AUTH_LOGIN_ATTEMPTS', 5),

    // Durata del blocco dopo troppi tentativi di accesso (minuti)
    'login_lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 1),

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
