<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('welcome'); // Placeholder: sostituito dal modulo dashboard
})->middleware('auth')->name('dashboard');

/*
|--------------------------------------------------------------------------
| Rotte dei moduli
|--------------------------------------------------------------------------
|
| Ogni modulo registra le proprie rotte in routes/modules/<modulo>.php.
| I file vengono caricati automaticamente: NON aggiungere rotte di modulo qui.
|
*/
foreach (glob(__DIR__.'/modules/*.php') as $moduleRoutesFile) {
    require $moduleRoutesFile;
}
