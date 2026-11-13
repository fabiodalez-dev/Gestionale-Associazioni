<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 2FA obbligatoria per il super admin
    |--------------------------------------------------------------------------
    |
    | Con il flag acceso un super admin senza autenticazione a due fattori
    | confermata, dopo il login, raggiunge solo la pagina profilo per
    | attivarla (più conferma password, rotte 2FA e logout). Le rotte API
    | (token Bearer), l'installer e la pagina aggiornamenti durante un
    | aggiornamento in corso non sono toccati.
    |
    | SUPER_ADMIN_2FA_OBBLIGATORIA=false la spegne. Nei test, se la variabile
    | non è impostata, il default è spento (le suite esistenti autenticano
    | super admin senza 2FA); impostata in phpunit.xml o con config() viene
    | rispettata come in produzione.
    |
    */

    'super_admin_2fa_obbligatoria' => (bool) env(
        'SUPER_ADMIN_2FA_OBBLIGATORIA',
        env('APP_ENV') !== 'testing',
    ),

];
