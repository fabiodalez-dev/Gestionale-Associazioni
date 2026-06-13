<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aggiornamento in-app (dashboard super_admin)
    |--------------------------------------------------------------------------
    | Controllo, download e applicazione degli aggiornamenti dalla pagina
    | /aggiornamenti. Le release vengono lette dalle GitHub Releases del repo.
    */

    // Versione installata: letta dal file VERSION (fonte di verità), con
    // fallback a composer.json. Il file VERSION viene sovrascritto a ogni update.
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.1.0',

    'github' => [
        'owner' => env('UPDATE_GITHUB_OWNER', 'fabiodalez-dev'),
        'repo' => env('UPDATE_GITHUB_REPO', 'gestione-associazioni-laravel'),
        // Token opzionale per alzare il rate limit GitHub (60 → 5000/h) e
        // accedere a repo privati. Mai committato: solo via env.
        'token' => env('UPDATE_GITHUB_TOKEN'),
    ],

    // Canale: 'stable' usa /releases/latest (esclude prerelease); 'rc' include
    // le prerelease.
    'channel' => env('UPDATE_CHANNEL', 'stable'),

    // Abilita l'applicazione effettiva dell'update (download+estrazione+migrate).
    // Se false la pagina mostra solo lo stato e le istruzioni manuali (utile su
    // hosting senza permessi di scrittura sui file applicativi).
    'self_update_enabled' => env('UPDATE_SELF_ENABLED', true),

    // Percorsi preservati durante la sovrascrittura dei file (mai toccati).
    'preserve_paths' => [
        '.env',
        'storage',
        'public/storage',
        'public/uploads',
        'VERSION',
    ],

    // Percorsi ignorati nello zip della release.
    'skip_paths' => ['.git', 'node_modules', 'tests', '.github'],

    'temp_path' => storage_path('app/updates'),

    // Timeout download zip (secondi).
    'download_timeout' => 300,
];
