@extends('layouts.app')

@section('title', 'Adempimenti ricorrenti')

@php
    $mesi = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',7=>'Luglio',8=>'Agosto',9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'];
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-calendar2-check me-2"></i>Adempimenti ricorrenti</h1>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('scadenzario.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar-week me-1"></i>Scadenzario</a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#adempimentoModal">
            <i class="bi bi-plus-lg me-1"></i>Nuovo adempimento
        </button>
    </div>
</div>

@include('partials.flash')

<p class="text-muted">
    Gli adempimenti ricorrenti generano ogni anno una voce nella checklist dello Scadenzario.
    La spunta annuale (completato/da fare) si gestisce dalla pagina Scadenzario.
</p>

@unless ($esistonoAdempimenti)
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-lightbulb me-1"></i>Non hai ancora adempimenti. Vuoi partire dai suggerimenti tipici (assicurazione volontari, bilancio, rinnovo cariche…)?</span>
    <form method="POST" action="{{ route('adempimenti.seed') }}">
        @csrf
        <button type="submit" class="btn btn-primary btn-sm">Aggiungi adempimenti suggeriti</button>
    </form>
</div>
@endunless

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Mese</th>
                <th>Titolo</th>
                <th>Descrizione</th>
                <th>Stato</th>
                <th class="text-end"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($adempimenti as $adempimento)
                <tr>
                    <td>{{ $mesi[$adempimento->mese_scadenza] ?? $adempimento->mese_scadenza }}</td>
                    <td class="fw-semibold">{{ $adempimento->titolo }}</td>
                    <td class="text-muted">{{ $adempimento->descrizione }}</td>
                    <td>
                        @if ($adempimento->attivo)
                            <span class="badge bg-success">Attivo</span>
                        @else
                            <span class="badge bg-secondary">Disattivo</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('adempimenti.index', ['edit' => $adempimento->id]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('adempimenti.destroy', $adempimento) }}" class="d-inline" onsubmit="return confirm('Eliminare l\'adempimento?');">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Nessun adempimento configurato.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Modal nuovo/modifica adempimento --}}
@php $edit = $adempimentoInModifica; @endphp
<div class="modal fade" id="adempimentoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ $edit ? route('adempimenti.update', $edit) : route('adempimenti.store') }}">
                @csrf
                @if ($edit) @method('PUT') @endif
                <div class="modal-header">
                    <h5 class="modal-title">{{ $edit ? 'Modifica adempimento' : 'Nuovo adempimento' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Titolo <span class="text-danger">*</span></label>
                        <input type="text" name="titolo" class="form-control @error('titolo') is-invalid @enderror"
                               value="{{ old('titolo', $edit?->titolo) }}" maxlength="200" required>
                        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mese di scadenza <span class="text-danger">*</span></label>
                        <select name="mese_scadenza" class="form-select" required>
                            @foreach ($mesi as $num => $nome)
                                <option value="{{ $num }}" @selected((int) old('mese_scadenza', $edit?->mese_scadenza) === $num)>{{ $nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrizione</label>
                        <textarea name="descrizione" class="form-control" rows="2" maxlength="2000">{{ old('descrizione', $edit?->descrizione) }}</textarea>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="attivo" value="1" class="form-check-input" id="adempAttivo"
                               @checked(old('attivo', $edit?->attivo ?? true))>
                        <label class="form-check-label" for="adempAttivo">Attivo</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if (($adempimentoInModifica) || ($errors->any() && old('titolo')))
<script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal(document.getElementById('adempimentoModal')).show());</script>
@endif
@endsection
