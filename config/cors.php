<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | L'API REST /api/v1 è consumata solo server-to-server o dalla pagina di
    | check-in servita dalla stessa origine, con autenticazione Bearer (API
    | key): nessuna origine cross-site è ammessa, quindi nessun header
    | Access-Control-Allow-* viene emesso, salvo le origini elencate in
    | CORS_ALLOWED_ORIGINS. Senza questo file Laravel applicherebbe il
    | default del framework (allowed_origins ['*']).
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    // Origini extra ammesse (separate da virgola in CORS_ALLOWED_ORIGINS):
    // per integrazioni browser già in uso prima di questa chiusura
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'X-API-Key', 'Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
