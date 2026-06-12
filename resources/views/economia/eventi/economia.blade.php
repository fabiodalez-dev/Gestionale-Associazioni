@extends('layouts.app')

@section('title', 'Economia evento — '.$evento->titolo)

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-cash-stack me-2"></i>Economia evento</h1>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Eventi
        </a>
    </div>
</div>

<div class="mb-3">
    <div class="fw-semibold fs-5">{{ $evento->titolo }}</div>
    <div class="text-muted small">
        <i class="bi bi-calendar-event me-1"></i>{{ $evento->data_evento?->format('d/m/Y H:i') }}
        @if (filled($evento->luogo ?? null))
            &middot; <i class="bi bi-geo-alt me-1"></i>{{ $evento->luogo }}
        @endif
    </div>
</div>

{{-- Riepilogo economico --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Budget previsto</div>
            <div class="fs-5 fw-semibold">{{ $evento->budget !== null ? '€ '.number_format((float) $evento->budget, 2, ',', '.') : '—' }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Incassi (prenotazioni pagate)</div>
            <div class="fs-5 fw-semibold text-success">&euro; {{ number_format($incassi, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Spese</div>
            <div class="fs-5 fw-semibold text-danger">&euro; {{ number_format($totaleSpese, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Margine</div>
            <div class="fs-5 fw-semibold {{ $margine >= 0 ? 'text-success' : 'text-danger' }}">&euro; {{ number_format($margine, 2, ',', '.') }}</div>
        </div></div>
    </div>
</div>

@if ($evento->budget !== null && $totaleSpese > (float) $evento->budget)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Le spese superano il budget previsto di
        <strong>&euro; {{ number_format($totaleSpese - (float) $evento->budget, 2, ',', '.') }}</strong>.
    </div>
@endif

<div class="row g-3">
    {{-- Budget --}}
    @can('update', $evento)
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-bullseye me-1"></i>Budget previsto</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('eventi.economia.budget', $evento) }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label for="campoBudget" class="form-label">Budget (&euro;)</label>
                            <input type="number" step="0.01" min="0" name="budget" id="campoBudget" class="form-control"
                                   value="{{ old('budget', $evento->budget) }}" placeholder="Vuoto = non gestito">
                            @error('budget')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva budget</button>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    {{-- Spese --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-receipt me-1"></i>Spese dell'evento</div>
            <div class="card-body">
                @can('update', $evento)
                    <form method="POST" action="{{ route('eventi.economia.spese.store', $evento) }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-5">
                            <input type="text" name="descrizione" class="form-control" placeholder="Descrizione *"
                                   value="{{ old('descrizione') }}" required maxlength="255">
                            @error('descrizione')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" min="0.01" name="importo" class="form-control"
                                   placeholder="Importo € *" value="{{ old('importo') }}" required>
                            @error('importo')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <input type="date" name="data" class="form-control" value="{{ old('data', now()->toDateString()) }}" required>
                            @error('data')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100" title="Aggiungi spesa"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                @endcan

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>Data</th><th>Descrizione</th><th class="text-end">Importo</th><th class="text-end"></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($spese as $spesa)
                                <tr>
                                    <td>{{ $spesa->data?->format('d/m/Y') }}</td>
                                    <td>{{ $spesa->descrizione }}</td>
                                    <td class="text-end">&euro; {{ number_format((float) $spesa->importo, 2, ',', '.') }}</td>
                                    <td class="text-end">
                                        @can('update', $evento)
                                            <form method="POST" action="{{ route('eventi.economia.spese.destroy', $spesa) }}" class="d-inline"
                                                  onsubmit="return confirm('Eliminare questa spesa?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">Nessuna spesa registrata.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
