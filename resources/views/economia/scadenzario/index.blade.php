@extends('layouts.app')

@section('title', 'Scadenzario')

@php
    $tipoBadge = [
        'quota' => ['credit-card', 'info', 'Quota'],
        'documento' => ['file-earmark', 'warning', 'Documento'],
        'carica' => ['person-badge', 'primary', 'Mandato'],
        'scadenza' => ['calendar-check', 'secondary', 'Scadenza'],
    ];
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-calendar-week me-2"></i>Scadenzario</h1>
</div>

<p class="text-muted">
    Vista unificata di tutte le scadenze: quote da pagare, documenti dei soci,
    mandati delle cariche sociali e scadenze personalizzate.
</p>

<form method="GET" action="{{ route('scadenzario.index') }}" class="row g-2 mb-3">
    <div class="col-auto">
        <label for="filtroTipo" class="form-label mb-0 small text-muted">Tipo</label>
        <select name="tipo" id="filtroTipo" class="form-select">
            <option value="">Tutti</option>
            <option value="quota" @selected($filtroTipo === 'quota')>Quote</option>
            <option value="documento" @selected($filtroTipo === 'documento')>Documenti socio</option>
            <option value="carica" @selected($filtroTipo === 'carica')>Mandati cariche</option>
            <option value="scadenza" @selected($filtroTipo === 'scadenza')>Scadenze custom</option>
        </select>
    </div>
    <div class="col-auto">
        <label for="filtroDal" class="form-label mb-0 small text-muted">Dal</label>
        <input type="date" name="dal" id="filtroDal" class="form-control" value="{{ $dal->toDateString() }}">
    </div>
    <div class="col-auto">
        <label for="filtroAl" class="form-label mb-0 small text-muted">Al</label>
        <input type="date" name="al" id="filtroAl" class="form-control" value="{{ $al->toDateString() }}">
    </div>
    <div class="col-auto align-self-end">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
        <a href="{{ route('scadenzario.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th>Titolo</th>
                <th>Dettaglio</th>
                <th class="text-end"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($voci as $voce)
                @php
                    [$icona, $colore, $etichetta] = $tipoBadge[$voce['tipo']] ?? ['dot', 'secondary', $voce['tipo']];
                    $scaduta = $voce['data'] !== null && $voce['data']->isPast();
                @endphp
                <tr>
                    <td class="{{ $scaduta ? 'text-danger fw-semibold' : '' }}">
                        {{ $voce['data']?->format('d/m/Y') }}
                        @if ($scaduta)
                            <i class="bi bi-exclamation-triangle ms-1" title="Scaduta"></i>
                        @endif
                    </td>
                    <td><span class="badge bg-{{ $colore }}"><i class="bi bi-{{ $icona }} me-1"></i>{{ $etichetta }}</span></td>
                    <td>{{ $voce['titolo'] }}</td>
                    <td class="text-muted">{{ $voce['dettaglio'] }}</td>
                    <td class="text-end">
                        @if (! empty($voce['url']))
                            <a href="{{ $voce['url'] }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Nessuna scadenza nel periodo selezionato.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
