<?php

use App\Http\Middleware\ApiV1\EnsureApiInstallata;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotte API
|--------------------------------------------------------------------------
|
| Questo file è caricato da bootstrap/app.php nel gruppo `api`, che aggiunge
| già il prefisso /api: qui basta "v1". Il gruppo `api` NON avvia la
| sessione e non scrive cookie (niente StartSession, EncryptCookies, CSRF,
| EnsureInstalled): i client REST si autenticano solo con chiave API o token
| Sanctum (AuthenticateApiV1).
|
| Le rotte /api/v1 dei moduli stanno in routes/modules/api/*.php (fuori dal
| glob di routes/web.php, che carica solo routes/modules/*.php). Stessi URL,
| nomi di rotta e middleware di prima.
|
| - EnsureApiInstallata: con l'app non installata risponde 503 JSON invece
|   del redirect a /install di EnsureInstalled.
| - SecurityHeaders: stessi header di sicurezza che le risposte API avevano
|   nel gruppo web (la CSP era già esclusa).
|
| La rotta di default /api/user (auth:sanctum) è stata rimossa: non era
| usata e restituiva l'intero record dell'utente autenticato.
|
*/

Route::prefix('v1')
    ->middleware([EnsureApiInstallata::class, SecurityHeaders::class])
    ->group(function () {
        foreach (glob(__DIR__.'/modules/api/*.php') ?: [] as $apiRoutesFile) {
            require $apiRoutesFile;
        }
    });
