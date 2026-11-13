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
    | Access-Control-Allow-* viene emesso. Senza questo file Laravel
    | applicherebbe il default del framework (allowed_origins ['*']).
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
