@extends('layouts.app')

@section('title', $esecuzione->titolo)

@php
    $fatti = $esecuzione->passi->whereNotNull('completato_at')->count();
    $totale = $esecuzione->passi->count();
@endphp

@section('content')
<div class="pt-3 pb-2 mb-3 border-bottom">
    <a href="{{ route('manuale.show', $esecuzione->pagina) }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>{{ $esecuzione->pagina->titolo }}</a>
    <h1 class="h2 mb-0">{{ $esecuzione->titolo }}</h1>
    <div class="small text-muted">
        Avviata il {{ $esecuzione->created_at?->format('d/m/Y') }}{{ $esecuzione->avviataDa ? ' da '.$esecuzione->avviataDa->nome_completo : '' }} ·
        <span class="tabular">{{ $fatti }}/{{ $totale }}</span> passi
        @if ($esecuzione->completata_at)<span class="text-success"> · completata il {{ $esecuzione->completata_at->format('d/m/Y') }}</span>@endif
    </div>
</div>

@include('partials.flash')

<div class="progress mb-4" role="progressbar" aria-label="Avanzamento" aria-valuenow="{{ $fatti }}" aria-valuemin="0" aria-valuemax="{{ $totale }}" style="height: .5rem">
    <div class="progress-bar" style="width: {{ $totale > 0 ? round($fatti / $totale * 100) : 0 }}%"></div>
</div>

<div class="list-group">
    @foreach ($esecuzione->passi as $passo)
        <form method="POST" action="{{ route('manuale.esecuzioni.passo', [$esecuzione, $passo]) }}" class="list-group-item">
            @csrf
            <div class="d-flex gap-3 align-items-start">
                {{-- Primo pulsante del form: Invio nelle note salva senza cambiare la spunta --}}
                <button type="submit" name="completato" value="{{ $passo->completato_at ? 1 : 0 }}" class="visually-hidden" tabindex="-1">Salva note</button>
                <button type="submit" name="completato" value="{{ $passo->completato_at ? 0 : 1 }}" class="btn btn-sm {{ $passo->completato_at ? 'btn-success' : 'btn-outline-secondary' }}" title="{{ $passo->completato_at ? 'Togli la spunta' : 'Segna come fatto' }}" aria-label="{{ $passo->completato_at ? 'Togli la spunta' : 'Segna come fatto' }}">
                    <i class="bi {{ $passo->completato_at ? 'bi-check-lg' : 'bi-square' }}"></i>
                </button>
                <div class="flex-grow-1">
                    <div class="{{ $passo->completato_at ? 'text-decoration-line-through text-muted' : 'fw-semibold' }}">{{ $passo->indice + 1 }}. {{ $passo->testo }}</div>
                    @if ($passo->completato_at)
                        <div class="small text-muted">Fatto il {{ $passo->completato_at->format('d/m/Y H:i') }}{{ $passo->completatoDa ? ' da '.$passo->completatoDa->nome_completo : '' }}</div>
                    @endif
                    <input type="text" name="note" class="form-control form-control-sm mt-1" maxlength="2000" value="{{ $passo->note }}" placeholder="Note (facoltative)" aria-label="Note del passo">
                </div>
            </div>
        </form>
    @endforeach
</div>
@endsection
