@extends('installer.layout')

@section('title', 'Requisiti')
@section('step-label', '1 di 5')

@section('content')
    <h2>Requisiti di sistema</h2>
    <p class="subtitle">Verifica che il server soddisfi i requisiti minimi.</p>

    <ul class="check-list mb-3">
        <li>
            <span>PHP &ge; {{ $req['php']['required'] }} <span class="text-muted">(attuale: {{ $req['php']['current'] }})</span></span>
            <i class="bi {{ $req['php']['ok'] ? 'bi-check-circle-fill check-ok' : 'bi-x-circle-fill check-ko' }}"></i>
        </li>
        @foreach ($req['extensions'] as $ext => $ok)
            <li>
                <span>Estensione <code>{{ $ext }}</code></span>
                <i class="bi {{ $ok ? 'bi-check-circle-fill check-ok' : 'bi-x-circle-fill check-ko' }}"></i>
            </li>
        @endforeach
        @foreach ($req['writable'] as $path => $ok)
            <li>
                <span><code>{{ $path }}</code> scrivibile</span>
                <i class="bi {{ $ok ? 'bi-check-circle-fill check-ok' : 'bi-x-circle-fill check-ko' }}"></i>
            </li>
        @endforeach
        <li>
            <span>File <code>.env</code> scrivibile</span>
            <i class="bi {{ $req['env_writable'] ? 'bi-check-circle-fill check-ok' : 'bi-x-circle-fill check-ko' }}"></i>
        </li>
    </ul>

    @if ($req['ok'])
        <a href="{{ route('installer.database') }}" class="btn btn-primary w-100">
            Continua<i class="bi bi-arrow-right ms-2"></i>
        </a>
    @else
        <div class="alert alert-warning mb-3">
            Risolvi i requisiti mancanti e ricarica la pagina.
        </div>
        <a href="{{ route('installer.requirements') }}" class="btn btn-outline-secondary w-100">
            <i class="bi bi-arrow-clockwise me-2"></i>Ricontrolla
        </a>
    @endif
@endsection
