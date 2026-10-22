<?php

use App\Formello\Widgets\ConsensoWidget;
use App\Formello\Widgets\PasswordWidget;

return [

    /**
     * Options bootstrap5 tailwindcss4
     */
    'css_framework' => 'bootstrap5',

    /**
     * Customize the CSS classes of the various widgets
     */
    'css_overrides' => [
        'help_text' => 'form-text',
        'labels' => 'form-label',
        'errors' => 'invalid-feedback',
    ],

    /**
     * Custom widgets registered by the application.
     * Map alias => Fully Qualified Class Name. These override built-ins.
     */
    'custom_widgets' => [
        // Password con indicatore di robustezza (zxcvbn) e pulsante mostra/nascondi
        'password' => PasswordWidget::class,
        // Casella singola di consenso (privacy, invito) con etichetta che può contenere un link
        'consenso' => ConsensoWidget::class,
    ],

    /**
     * Asset loading configuration
     * Set to false any library you already have in your theme to avoid conflicts
     */
    /**
     * Reactive system configuration
     */
    'reactive' => [
        // Disattivata: nessun form usa callback reattivi lato server, e la rotta
        // istanzierebbe classi scelte dal client. Per riattivarla elencare i form
        // ammessi in allowed_forms (con allowlist vuota il pacchetto li accetta tutti).
        'compute_path' => null,
        'allowed_forms' => [],
    ],

    // I form dell'app usano solo widget senza librerie esterne: niente asset di Formello
    'assets' => [
        'select2' => false, // deprecated
        'tomselect' => false,
        'date' => false,
        'datetime' => false,
        'mask' => false,
        'color' => false,
        'colorswatch' => false,
        'wysiwyg' => false,
    ],

];
