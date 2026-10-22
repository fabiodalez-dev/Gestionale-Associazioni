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
    }
</style>
