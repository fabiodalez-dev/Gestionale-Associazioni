<?php

use App\Http\Controllers\Api\V1\EventoApiController;
use App\Http\Middleware\ApiV1\AuthenticateApiV1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo eventi: API REST /api/v1
|--------------------------------------------------------------------------
|
| Caricato da routes/api.php DENTRO il gruppo `api` con prefisso /api/v1
| (niente sessione, cookie, CSRF né CSP). Stessa autenticazione del modulo
| api-v1 (AuthenticateApiV1: chiavi api_keys hashate o token Sanctum, scoped
| o globali), permessi granulari eventi:read / eventi:write, throttle api-v1
| PRIMA dell'autenticazione.
|
*/

Route::get('/', [EventoApiController::class, 'ping'])
    ->middleware(['throttle:api-v1', AuthenticateApiV1::class])
    ->name('api.v1.ping');

Route::get('/eventi', [EventoApiController::class, 'index'])
    ->middleware(['throttle:api-v1', AuthenticateApiV1::class.':eventi:read'])
    ->name('api.v1.eventi.index');

Route::get('/eventi/{id}', [EventoApiController::class, 'show'])
    ->middleware(['throttle:api-v1', AuthenticateApiV1::class.':eventi:read'])
    ->name('api.v1.eventi.show');

Route::post('/eventi/{id}/checkin', [EventoApiController::class, 'checkin'])
    ->middleware(['throttle:api-v1', AuthenticateApiV1::class.':eventi:write'])
    ->name('api.v1.eventi.checkin');
