<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel Scout — ricerca full-text (miglioramento 14)
    |--------------------------------------------------------------------------
    |
    | Driver 'database': nessuna infrastruttura esterna, funziona su MySQL
    | (produzione) e sqlite (test). Le colonne ricercabili sono definite
    | in toSearchableArray() dei modelli (es. App\Models\Socio).
    |
    */

    'driver' => env('SCOUT_DRIVER', 'database'),

    'prefix' => env('SCOUT_PREFIX', ''),

    'queue' => env('SCOUT_QUEUE', false),

    'after_commit' => false,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => env('SCOUT_IDENTIFY', false),

];
