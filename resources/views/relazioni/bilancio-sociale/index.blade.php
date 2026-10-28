@extends('layouts.app')

@section('title', 'Bilancio sociale')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <h1 class="h2"><i class="bi bi-journal-richtext me-2"></i>Bilancio sociale</h1>
    @can('create', \App\Models\BilancioSociale::class)
        <form method="POST" action="{{ route('bilancio-sociale.store') }}" class="d-flex gap-2">
            @csrf
            <input type="number" name="anno" class="form-control form-control-sm" style="width: 6rem" min="2000" max="{{ now()->year }}" value="{{ now()->year - 1 }}" aria-label="Anno">
            <button type="submit" class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-plus-lg me-1"></i>Nuovo bilancio</button>
        </form>
    @endcan
</div>

@include('partials.flash')

<p class="text-muted">Il bilancio sociale racconta l'anno dell'associazione secondo le linee guida per gli enti del Terzo settore. Il gestionale prepara i numeri; tu scrivi il racconto di ogni sezione.</p>

<div class="list-group">
    @forelse ($bilanci as $bilancio)
        <a href="{{ route('bilancio-sociale.show', $bilancio) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Bilancio sociale {{ $bilancio->anno }}</span>
            <span class="small text-muted">{{ $bilancio->statoLabel() }} · dati del {{ $bilancio->generato_at?->format('d/m/Y') }}</span>
        </a>
    @empty
        <div class="list-group-item text-muted text-center py-4">Nessun bilancio sociale. Scegli l'anno e crea il primo.</div>
    @endforelse
</div>
@endsection
