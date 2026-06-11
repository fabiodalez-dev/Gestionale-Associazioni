<?php

use App\Http\Controllers\Installer\InstallerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Installer web (wizard /install)
|--------------------------------------------------------------------------
| Raggiungibile solo finché non esiste il lock file (middleware
| EnsureInstalled, in prepend sul gruppo web). I POST sono rate-limited.
*/
Route::prefix('install')->name('installer.')->group(function () {
    Route::get('/', [InstallerController::class, 'requirements'])->name('requirements');
    Route::get('/database', [InstallerController::class, 'database'])->name('database');
    Route::post('/database', [InstallerController::class, 'databaseStore'])
        ->middleware('throttle:10,1')->name('database.store');
    Route::get('/admin', [InstallerController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallerController::class, 'adminStore'])
        ->middleware('throttle:10,1')->name('admin.store');
    Route::get('/done', [InstallerController::class, 'done'])->name('done');
});
