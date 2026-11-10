@extends('installer.layout')

@section('title', 'Stato dell\'installazione')
@section('step-label', 'diagnosi')

@section('content')
    <h2>Stato dell'installazione</h2>
    <p class="subtitle">Cosa è già pronto e cosa manca, con il rimedio per ogni problema.</p>

    <ul class="check-list mb-4">
        @foreach ($voci as $voce)
            <li class="d-block">
                <div class="d-flex justify-content-between align-items-center">
                    <span>{{ $voce['voce'] }}</span>
                    <span class="{{ $voce['ok'] ? 'check-ok' : 'check-ko' }}">
                        <i class="bi {{ $voce['ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' }} me-1"></i>{{ $voce['dettaglio'] }}
                    </span>
                </div>
                @if ($voce['rimedio'])
                    <div class="small text-muted mt-1">{{ $voce['rimedio'] }}</div>
                @endif
            </li>
        @endforeach
    </ul>

    <a href="{{ route('installer.requirements') }}" class="btn btn-outline-secondary w-100">
        <i class="bi bi-arrow-left me-2"></i>Torna all'installazione
    </a>
@endsection
