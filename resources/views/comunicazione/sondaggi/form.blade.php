@extends('layouts.app')

@php $isEdit = $sondaggio->exists; @endphp

@section('title', $isEdit ? 'Modifica sondaggio' : 'Nuovo sondaggio')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-bar-chart me-2"></i>{{ $isEdit ? 'Modifica sondaggio' : 'Nuovo sondaggio' }}</h1>
    <a href="{{ route('sondaggi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Sondaggi
    </a>
</div>

@if ($isEdit && $sondaggio->risposte()->exists())
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Questo sondaggio ha già delle risposte: modificare le opzioni può rendere
        incoerenti i risultati già raccolti.
    </div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ $isEdit ? route('sondaggi.update', $sondaggio) : route('sondaggi.store') }}">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="mb-3">
                <label class="form-label" for="domanda">Domanda *</label>
                <input type="text" class="form-control @error('domanda') is-invalid @enderror"
                       id="domanda" name="domanda" maxlength="500" required
                       value="{{ old('domanda', $sondaggio->domanda) }}">
                @error('domanda')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label" for="opzioni_testo">Opzioni di risposta * <span class="text-muted">(una per riga, min 2)</span></label>
                <textarea class="form-control @error('opzioni') is-invalid @enderror"
                          id="opzioni_testo" name="opzioni_testo" rows="5" required>{{ old('opzioni_testo', implode("\n", (array) $sondaggio->opzioni)) }}</textarea>
                @error('opzioni')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('opzioni.*')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label" for="aperto_dal">Aperto dal</label>
                    <input type="datetime-local" class="form-control @error('aperto_dal') is-invalid @enderror"
                           id="aperto_dal" name="aperto_dal"
                           value="{{ old('aperto_dal', $sondaggio->aperto_dal?->format('Y-m-d\TH:i')) }}">
                    <div class="form-text">Lascia vuoto per salvare come bozza (non votabile).</div>
                    @error('aperto_dal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="aperto_al">Aperto fino al</label>
                    <input type="datetime-local" class="form-control @error('aperto_al') is-invalid @enderror"
                           id="aperto_al" name="aperto_al"
                           value="{{ old('aperto_al', $sondaggio->aperto_al?->format('Y-m-d\TH:i')) }}">
                    <div class="form-text">Lascia vuoto per non chiudere automaticamente.</div>
                    @error('aperto_al')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-check form-switch mb-3">
                <input type="hidden" name="solo_soci" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="solo_soci"
                       name="solo_soci" value="1" @checked(old('solo_soci', $sondaggio->solo_soci))>
                <label class="form-check-label" for="solo_soci">
                    Solo soci (votabile dall'area soci e dagli account collegati a un socio)
                </label>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>{{ $isEdit ? 'Salva modifiche' : 'Crea sondaggio' }}
            </button>
        </form>
    </div>
</div>
@endsection
