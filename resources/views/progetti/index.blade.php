@extends('layouts.app')

@section('title', 'Progetti')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-kanban me-2"></i>Progetti</h1>
    @can('create', \App\Models\Progetto::class)
        <a href="{{ route('progetti.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Nuovo progetto
        </a>
    @endcan
</div>

{{-- Filtri --}}
<form method="GET" action="{{ route('progetti.index') }}" class="row g-2 mb-3">
    <div class="col-12 col-md-auto flex-grow-1">
        <label for="filtroSearch" class="form-label mb-0 small text-muted">Cerca</label>
        <input type="text" name="search" id="filtroSearch" class="form-control"
               value="{{ $filtri['search'] }}" placeholder="Nome del progetto">
    </div>
    <div class="col-auto">
        <label for="filtroStato" class="form-label mb-0 small text-muted">Stato</label>
        <select name="stato" id="filtroStato" class="form-select">
            <option value="">Tutti</option>
            @foreach (\App\Models\Progetto::STATI as $stato)
                <option value="{{ $stato }}" @selected($filtri['stato'] === $stato)>{{ ucfirst($stato) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto align-self-end">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
        <a href="{{ route('progetti.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Progetto</th>
                <th>Stato</th>
                <th>Periodo</th>
                <th class="text-end">Budget</th>
                <th class="text-center">Avanzamento</th>
                <th class="text-center">Collegamenti</th>
                <th class="text-end">Azioni</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($progetti as $progetto)
                @php
                    $avanzamento = $progetto->percentualeAvanzamento();
                    $badge = match ($progetto->stato) {
                        \App\Models\Progetto::STATO_ATTIVO => 'bg-success',
                        \App\Models\Progetto::STATO_CONCLUSO => 'bg-secondary',
                        default => 'bg-warning text-dark',
                    };
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('progetti.show', $progetto) }}" class="fw-semibold text-decoration-none">
                            {{ $progetto->nome }}
                        </a>
                    </td>
                    <td><span class="badge {{ $badge }}">{{ ucfirst($progetto->stato) }}</span></td>
                    <td class="small text-muted">
                        {{ $progetto->dal?->format('d/m/Y') ?? '—' }}
                        @if ($progetto->al) → {{ $progetto->al->format('d/m/Y') }}@endif
                    </td>
                    <td class="text-end">
                        {{ $progetto->budget !== null ? '€ '.number_format((float) $progetto->budget, 2, ',', '.') : '—' }}
                    </td>
                    <td class="text-center" style="min-width: 120px;">
                        @if ($progetto->milestones_count > 0)
                            <div class="progress" role="progressbar" aria-valuenow="{{ $avanzamento }}" aria-valuemin="0" aria-valuemax="100" style="height: 8px;">
                                <div class="progress-bar bg-primary" style="width: {{ $avanzamento }}%;"></div>
                            </div>
                            <small class="text-muted">{{ $avanzamento }}%</small>
                        @else
                            <small class="text-muted">—</small>
                        @endif
                    </td>
                    <td class="text-center small text-muted">
                        <span title="Eventi"><i class="bi bi-calendar-event"></i> {{ $progetto->eventi_count }}</span>
                        <span class="ms-2" title="Turni"><i class="bi bi-people"></i> {{ $progetto->turni_count }}</span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('progetti.show', $progetto) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Nessun progetto registrato.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $progetti->links() }}
@endsection
