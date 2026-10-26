@extends('layouts.app')

@section('title', 'Manuale operativo')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <h1 class="h2"><i class="bi bi-book me-2"></i>Manuale operativo</h1>
    @can('create', \App\Models\ManualePagina::class)
        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('manuale.modelli') }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-magic me-1"></i>Aggiungi procedure di esempio</button>
            </form>
            <a href="{{ route('manuale.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuova pagina</a>
        </div>
    @endcan
</div>

@include('partials.flash')

<p class="text-muted">Le procedure dell'associazione, scritte una volta e seguite ogni volta: chi arriva dopo trova come si fa.</p>

<form method="GET" action="{{ route('manuale.index') }}" class="mb-4" style="max-width: 32rem">
    <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="search" class="form-control" placeholder="Cerca nel manuale…" value="{{ $search }}" aria-label="Cerca nel manuale">
    </div>
</form>

@if ($inCorso->isNotEmpty())
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-list-check me-2"></i>Checklist in corso</div>
        <ul class="list-group list-group-flush">
            @foreach ($inCorso as $esecuzione)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <a href="{{ route('manuale.esecuzioni.show', $esecuzione) }}" class="text-decoration-none">{{ $esecuzione->titolo }}</a>
                    <span class="small text-muted tabular">{{ $esecuzione->passi_fatti_count }}/{{ $esecuzione->passi_count }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif

@forelse ($perSezione as $sezione => $pagine)
    <h2 class="h6 text-uppercase text-muted mt-4">{{ $sezione }}</h2>
    <div class="list-group mb-3">
        @foreach ($pagine as $pagina)
            <a href="{{ route('manuale.show', $pagina) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <span>
                    {{ $pagina->titolo }}
                    @if ($pagina->visibilita === 'soci')<span class="badge text-bg-light border ms-1">anche soci</span>@endif
                </span>
                <span class="small text-muted">
                    @if (count($pagina->elencoPassi()) > 0)<i class="bi bi-list-check me-1"></i>{{ count($pagina->elencoPassi()) }} passi ·@endif
                    rivista {{ $pagina->revisionata_at?->format('d/m/Y') ?? '—' }}
                </span>
            </a>
        @endforeach
    </div>
@empty
    <div class="text-center text-muted py-5">
        <i class="bi bi-book display-5 d-block mb-2"></i>
        {{ $search !== '' ? 'Nessuna pagina corrisponde alla ricerca.' : 'Il manuale è vuoto. Parti dalle procedure di esempio o scrivi la prima pagina.' }}
    </div>
@endforelse
@endsection
