@extends('layouts.app')

@section('title', 'Riunioni')

@php
    $statoBadge = [
        'pianificata' => ['secondary', 'Pianificata'],
        'svolta' => ['success', 'Svolta'],
        'annullata' => ['danger', 'Annullata'],
    ];
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-people me-2"></i>Riunioni del direttivo</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#riunioneModal">
            <i class="bi bi-plus-lg me-1"></i>Nuova riunione
        </button>
    </div>
</div>

@include('partials.flash')

<form method="GET" action="{{ route('riunioni.index') }}" class="row g-2 mb-3">
    <div class="col-md-5">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="search" class="form-control" placeholder="Cerca per titolo…" value="{{ $search }}">
        </div>
    </div>
    <div class="col-md-3">
        <select name="stato" class="form-select">
            <option value="all">Tutti gli stati</option>
            @foreach ($stati as $s)
                <option value="{{ $s }}" @selected($stato === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-auto">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
        <a href="{{ route('riunioni.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Data e ora</th>
                <th>Titolo</th>
                <th>Luogo</th>
                <th class="text-center">Punti OdG</th>
                <th class="text-center">Invitati</th>
                <th>Stato</th>
                <th>Verbale</th>
                <th class="text-end"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($riunioni as $riunione)
                @php [$colore, $etichetta] = $statoBadge[$riunione->stato] ?? ['secondary', $riunione->stato]; @endphp
                <tr>
                    <td>{{ $riunione->data_ora?->format('d/m/Y H:i') }}</td>
                    <td><a href="{{ route('riunioni.show', $riunione) }}" class="fw-semibold text-decoration-none">{{ $riunione->titolo }}</a></td>
                    <td class="text-muted">{{ $riunione->luogo ?? $riunione->spazio?->nome ?? '—' }}</td>
                    <td class="text-center">{{ is_array($riunione->odg) ? count($riunione->odg) : 0 }}</td>
                    <td class="text-center">{{ $riunione->partecipanti_count }}</td>
                    <td><span class="badge bg-{{ $colore }}">{{ $etichetta }}</span></td>
                    <td>
                        @if ($riunione->verbale)
                            <a href="{{ route('verbali.index', ['edit' => $riunione->verbale_id]) }}" class="text-decoration-none">
                                <i class="bi bi-journal-text me-1"></i>{{ $riunione->verbale->numero_completo }}
                            </a>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('riunioni.show', $riunione) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">Nessuna riunione registrata.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $riunioni->links() }}

{{-- Modal nuova riunione --}}
<div class="modal fade" id="riunioneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('riunioni.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Nuova riunione</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    @include('struttura.riunioni._form', ['riunione' => null, 'spazi' => $spazi, 'stati' => $stati])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea riunione</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if ($errors->any() && old('titolo'))
<script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal(document.getElementById('riunioneModal')).show());</script>
@endif
@endsection
