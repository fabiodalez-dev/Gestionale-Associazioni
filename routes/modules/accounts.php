<?php

use App\Http\Controllers\Accounts\AccountAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: accounts (gestione admin degli account del frontend pubblico)
|--------------------------------------------------------------------------
|
| Lista/dettaglio account, collegamento a socio (esplicito, mai automatico
| per email), promozione a socio e disattivazione/riattivazione.
| Autorizzazione via AccountPolicy (permessi spatie struttura.*).
| L'isolamento tenant è garantito dal global scope BelongsToAssociazione
| (binding cross-tenant → 404).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/accounts', [AccountAdminController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/{account}', [AccountAdminController::class, 'show'])->name('accounts.show');

    Route::post('/accounts/{account}/collega-socio', [AccountAdminController::class, 'collegaSocio'])
        ->name('accounts.collega-socio');
    Route::post('/accounts/{account}/promuovi-socio', [AccountAdminController::class, 'promuoviSocio'])
        ->name('accounts.promuovi-socio');

    Route::patch('/accounts/{account}/disattiva', [AccountAdminController::class, 'disattiva'])
        ->name('accounts.disattiva');
    Route::patch('/accounts/{account}/riattiva', [AccountAdminController::class, 'riattiva'])
        ->name('accounts.riattiva');
});
