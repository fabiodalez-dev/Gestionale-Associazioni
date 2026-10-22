<?php

use App\Http\Controllers\AllegatiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Allegati di verbali, riunioni e progetti (medialibrary, disco privato)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant'])->prefix('allegati/{tipo}/{id}')->name('allegati.')
    ->whereIn('tipo', array_keys(AllegatiController::TIPI))
    ->group(function (): void {
        Route::get('/', [AllegatiController::class, 'index'])->name('index');
        Route::post('/', [AllegatiController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('/{media}', [AllegatiController::class, 'download'])->whereNumber('media')->name('download');
        Route::delete('/{media}', [AllegatiController::class, 'destroy'])->whereNumber('media')->name('destroy');
    });
