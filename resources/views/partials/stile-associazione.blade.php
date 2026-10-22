{{-- Colore dell'associazione (ModuliSettings) per le pagine pubbliche: variabili CSS --brand*. --}}
@php
    $moduliStile = $associazione->moduli();
@endphp
<meta name="theme-color" content="{{ $moduliStile->colore_primario }}">
<style>
    :root {
        --brand: {{ $moduliStile->colore_primario }};
        --brand-testo: {{ $moduliStile->coloreTesto() }};
        --brand-hover: color-mix(in srgb, {{ $moduliStile->colore_primario }} 85%, #000);
        --brand-tenue: color-mix(in srgb, {{ $moduliStile->colore_primario }} 12%, #fff);
        --bs-primary: {{ $moduliStile->colore_primario }};
        --bs-primary-rgb: {{ $moduliStile->coloreRgb() }};
        --ds-accent: {{ $moduliStile->colore_primario }};
        --ds-accent-hover: color-mix(in srgb, {{ $moduliStile->colore_primario }} 85%, #000);
        --ds-accent-subtle: color-mix(in srgb, {{ $moduliStile->colore_primario }} 12%, #fff);
        --ds-accent-softer: color-mix(in srgb, {{ $moduliStile->colore_primario }} 6%, #fff);
        --ds-accent-mid: color-mix(in srgb, {{ $moduliStile->colore_primario }} 40%, #fff);
        --ds-accent-ink: color-mix(in srgb, {{ $moduliStile->colore_primario }} 82%, #000);
        --ds-accent-rgb: {{ $moduliStile->coloreRgb() }};
        --ds-accent-ring: rgba({{ $moduliStile->coloreRgb() }}, 0.2);
        --ds-shadow-accent: 0 2px 6px rgba({{ $moduliStile->coloreRgb() }}, 0.32);
    }
</style>
