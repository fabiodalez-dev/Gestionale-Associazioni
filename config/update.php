<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aggiornamento in-app (pagina /aggiornamenti, super_admin)
    |--------------------------------------------------------------------------
    | Stesso meccanismo dell'updater di Pinakes, adattato a Laravel: la release
    | GitHub pubblica il pacchetto completo <repo>-<tag>.zip (vendor e asset
    | inclusi, costruito da bin/build-release.sh); l'updater lo scarica, ne
    | verifica lo SHA256 con il digest dell'API GitHub, fa il backup di
    | database e file, lo copia sopra l'installazione, esegue le migration e
    | in caso di errore ripristina i file.
    */

    // Versione installata: file VERSION nella radice (sovrascritto dal pacchetto).
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.1.0',

    // Radice dell'installazione su cui lavora l'updater. Null = base_path().
    // I test la puntano su una cartella temporanea con un'app finta.
    'root_path' => env('UPDATE_ROOT_PATH'),

    'github' => [
        'owner' => env('UPDATE_GITHUB_OWNER', 'fabiodalez-dev'),
        'repo' => env('UPDATE_GITHUB_REPO', 'gestionale-associazioni'),
        // Token opzionale per alzare il rate limit GitHub (60 → 5000/h).
        // Mai committato: solo via .env (salvabile dalla pagina Aggiornamenti).
        'token' => env('UPDATE_GITHUB_TOKEN'),
    ],

    // Canale: 'stable' usa /releases/latest (esclude prerelease); 'rc' include
    // le prerelease.
    'channel' => env('UPDATE_CHANNEL', 'stable'),

    // Abilita l'applicazione effettiva dell'update dalla pagina web.
    // Se false la pagina mostra solo lo stato (utile dove i file applicativi
    // non sono scrivibili dall'utente del web server: si usa la CLI).
    'self_update_enabled' => env('UPDATE_SELF_ENABLED', true),

    // Percorsi mai sovrascritti né cancellati dall'aggiornamento (relativi
    // alla radice): configurazione e dati dell'installazione.
    'preserve_paths' => [
        '.env',
        'storage',
        'public/storage',
        'public/uploads',
        'public/.user.ini',
        '.htaccess.local',
        // Database locale (sviluppo o installazioni sqlite) e cache di
        // composer: mai cancellati dalla pulizia degli orfani
        'database/database.sqlite',
        'bootstrap/cache/packages.php',
        'bootstrap/cache/services.php',
    ],

    // Percorsi del pacchetto ignorati in copia.
    'skip_paths' => ['.git', 'node_modules', 'tests', '.github'],

    // Cartelle di codice in cui i file non più presenti nel pacchetto vengono
    // cancellati dopo la copia (classi rimosse, dipendenze dismesse).
    'orphan_dirs' => ['app', 'config', 'database', 'lang', 'resources', 'routes', 'vendor', 'public/assets', 'public/build', 'bin'],

    // Area di lavoro: pacchetto scaricato, estrazione, backup dei file.
    'temp_path' => 'storage/app/updates',

    // Backup del database fatti dall'updater in PHP puro quando
    // spatie/laravel-backup (mysqldump) non è disponibile.
    'db_backup_path' => 'storage/app/private/aggiornamenti',

    'download_timeout' => 300,

    // Preflight
    'min_php' => '8.3.0',
    'min_free_bytes' => 200 * 1024 * 1024,
    'probe_bytes' => 1024 * 1024,

    // Manutenzione lasciata accesa da un update interrotto: oltre questi
    // minuti la pagina Aggiornamenti permette di sbloccarla.
    'maintenance_stale_minutes' => 30,
];
