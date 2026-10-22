<?php

use App\Http\Controllers\Admin\JobFallitiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Code e job falliti (solo super_admin, controllo nel controller)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->prefix('job-falliti')->name('job-falliti.')->group(function (): void {
    Route::get('/', [JobFallitiController::class, 'index'])->name('index');
    Route::post('/riprova-tutti', [JobFallitiController::class, 'retryAll'])->name('retry-all');
    Route::delete('/elimina-tutti', [JobFallitiController::class, 'destroyAll'])->name('destroy-all');
    Route::get('/{uuid}', [JobFallitiController::class, 'show'])->whereUuid('uuid')->name('show');
    Route::post('/{uuid}/riprova', [JobFallitiController::class, 'retry'])->whereUuid('uuid')->name('retry');
    Route::delete('/{uuid}', [JobFallitiController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
});
