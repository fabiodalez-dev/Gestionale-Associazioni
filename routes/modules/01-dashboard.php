<?php

use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: dashboard
|--------------------------------------------------------------------------
|
| La dashboard NON usa il middleware 'tenant': il super_admin senza
| associazione selezionata vi accede in vista globale (come nel legacy).
| Sostituisce la rotta placeholder definita in routes/web.php.
|
*/

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
