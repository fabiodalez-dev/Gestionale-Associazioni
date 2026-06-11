<?php

use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\TenantSwitchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: auth
|--------------------------------------------------------------------------
|
| Login, logout, 2FA e cambio password sono gestiti dalle rotte Fortify
| (GET/POST /login, POST /logout, /two-factor-challenge, PUT /user/password,
| POST /user/two-factor-authentication, ...).
| Qui: pagina profilo e context switch tenant del super_admin.
|
*/

Route::middleware(['auth'])->group(function () {
    // Pagina profilo: cambio password + attivazione/gestione 2FA
    Route::get('/profilo', [ProfileController::class, 'show'])->name('profile.show');

    // Selettore associazione del super_admin (sidebar)
    Route::post('/tenant/switch', TenantSwitchController::class)->name('tenant.switch');
});
