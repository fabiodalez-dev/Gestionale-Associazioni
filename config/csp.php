<?php

return [

    /*
     * I preset determinano gli header CSP. Un preset valido è una qualsiasi
     * classe che implementa `Spatie\Csp\Preset`.
     */
    'presets' => [
        App\Support\Csp\AppCspPreset::class,
    ],

    /**
     * Direttive CSP globali aggiuntive.
     */
    'directives' => [
        // [Directive::SCRIPT, [Keyword::UNSAFE_EVAL]],
    ],

    /*
     * Preset applicati in modalità report-only (utile per testare nuove policy).
     */
    'report_only_presets' => [
        //
    ],

    /**
     * Direttive report-only aggiuntive.
     */
    'report_only_directives' => [
        //
    ],

    /*
     * Le violazioni della policy vengono segnalate a questo endpoint.
     */
    'report_uri' => env('CSP_REPORT_URI', ''),

    /*
     * Report URI separato per la policy report-only (fallback su report_uri).
     */
    'report_only_uri' => env('CSP_REPORT_ONLY_URI', ''),

    /*
     * Nome dell'endpoint di reporting per le violazioni.
     */
    'report_to' => env('CSP_REPORT_TO', ''),

    'report_only_to' => env('CSP_REPORT_ONLY_TO', ''),

    /*
     * Endpoint inviati nell'header `Reporting-Endpoints`.
     */
    'reporting_endpoints' => [
        //
    ],

    /*
     * Header inviati solo se abilitato.
     */
    'enabled' => env('CSP_ENABLED', true),

    /*
     * Header CSP anche sulle risposte vista in caso di eccezione.
     */
    'enabled_while_serving_exceptions' => env('CSP_ENABLED_WHILE_SERVING_EXCEPTIONS', false),

    /*
     * Generatore del nonce (non usato: la policy consente 'unsafe-inline'
     * per compatibilità con Bootstrap/TinyMCE/GrapesJS come nel legacy).
     */
    'nonce_generator' => Spatie\Csp\Nonce\RandomString::class,

    /*
     * Imposta a false per disabilitare il nonce nei contesti in cui non è desiderato.
     */
    'nonce_enabled' => env('CSP_NONCE_ENABLED', false),
];
