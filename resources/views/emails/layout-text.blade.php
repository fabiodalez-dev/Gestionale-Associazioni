{{-- Versione plain-text del layout email brandizzato (f.18). --}}
@php
    $brandNome = $brandNome ?? config('app.name');
    $annoCorrente = $annoCorrente ?? now()->format('Y');
@endphp
{{ $brandNome }}
==============================

@if (!empty($greeting))
{{ $greeting }}

@endif
@foreach ($introLines ?? [] as $line)
{{ $line }}

@endforeach
@if (!empty($actionText) && !empty($actionUrl))
{{ $actionText }}: {{ $actionUrl }}

@endif
@foreach ($outroLines ?? [] as $line)
{{ $line }}

@endforeach
@if (!empty($salutation))
{{ $salutation }}

@endif
------------------------------
(c) {{ $annoCorrente }} {{ $brandNome }}
@if (!empty($unsubscribeUrl))
Disiscriviti dalle email: {{ $unsubscribeUrl }}
@endif
