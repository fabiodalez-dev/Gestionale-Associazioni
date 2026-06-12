@extends('layouts.app')

@section('title', 'Risultati sondaggio')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1"><i class="bi bi-bar-chart me-2"></i>Risultati sondaggio</h1>
        <p class="text-muted mb-0">
            @if ($sondaggio->isAperto())
                <span class="badge bg-success">Aperto</span>
            @elseif ($sondaggio->aperto_dal === null)
                <span class="badge bg-secondary">Bozza</span>
            @elseif ($sondaggio->aperto_dal->isFuture())
                <span class="badge bg-info text-dark">Programmato</span>
            @else
                <span class="badge bg-dark">Chiuso</span>
            @endif
            @if ($sondaggio->solo_soci)
                <span class="badge bg-primary">Solo soci</span>
            @endif
        </p>
    </div>
    <div class="d-flex gap-2">
        @can('update', $sondaggio)
            <a href="{{ route('sondaggi.edit', $sondaggio) }}" class="btn btn-outline-warning btn-sm">
                <i class="bi bi-pencil me-1"></i>Modifica
            </a>
        @endcan
        <a href="{{ route('sondaggi.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Sondaggi
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">{{ $sondaggio->domanda }}</div>
    <div class="card-body">
        <p class="text-muted">
            <i class="bi bi-people me-1"></i>{{ $totaleVoti }} {{ $totaleVoti === 1 ? 'voto totale' : 'voti totali' }}
            @if ($sondaggio->aperto_dal)
                — aperto dal {{ $sondaggio->aperto_dal->format('d/m/Y H:i') }}
                @if ($sondaggio->aperto_al) al {{ $sondaggio->aperto_al->format('d/m/Y H:i') }} @endif
            @endif
        </p>

        @foreach (array_values((array) $sondaggio->opzioni) as $indice => $opzione)
            @php
                $voti = $risultati[$indice] ?? 0;
                $percentuale = $totaleVoti > 0 ? round($voti / $totaleVoti * 100) : 0;
            @endphp
            <div class="mb-3">
                <div class="d-flex justify-content-between small mb-1">
                    <span>{{ $opzione }}</span>
                    <span class="text-muted">{{ $voti }} {{ $voti === 1 ? 'voto' : 'voti' }} ({{ $percentuale }}%)</span>
                </div>
                <div class="progress" role="progressbar" aria-valuenow="{{ $percentuale }}"
                     aria-valuemin="0" aria-valuemax="100" style="height: 22px;">
                    <div class="progress-bar" style="width: {{ $percentuale }}%">{{ $percentuale > 8 ? $percentuale.'%' : '' }}</div>
                </div>
            </div>
        @endforeach

        @if ($totaleVoti === 0)
            <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i>Nessun voto registrato finora.</p>
        @endif
    </div>
</div>
@endsection
