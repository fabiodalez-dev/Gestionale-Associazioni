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
        'compute_path' => '/formello/compute',
        // Whitelist of form classes allowed for server callbacks.
        // Leave empty to allow all (not recommended in production).
        'allowed_forms' => [
            // App\Forms\ContractForm::class,
        ],
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
