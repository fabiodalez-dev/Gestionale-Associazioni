@extends('layouts.app')

@php $isEdit = $annuncio->exists; @endphp

@section('title', $isEdit ? 'Modifica annuncio' : 'Nuovo annuncio')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-megaphone me-2"></i>{{ $isEdit ? 'Modifica annuncio' : 'Nuovo annuncio' }}</h1>
    <a href="{{ route('annunci.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Annunci
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ $isEdit ? route('annunci.update', $annuncio) : route('annunci.store') }}">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="mb-3">
                <label class="form-label" for="titolo">Titolo *</label>
                <input type="text" class="form-control @error('titolo') is-invalid @enderror"
                       id="titolo" name="titolo" maxlength="255" required
                       value="{{ old('titolo', $annuncio->titolo) }}">
                @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label" for="corpo_html">Contenuto *</label>
                <textarea class="form-control @error('corpo_html') is-invalid @enderror"
                          id="corpo_html" name="corpo_html" rows="10" required>{{ old('corpo_html', $annuncio->corpo_html) }}</textarea>
                <div class="form-text">HTML semplice consentito (paragrafi, liste, link, immagini): viene ripulito al salvataggio.</div>
                @error('corpo_html')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label" for="pubblicato_dal">Pubblicato dal</label>
                    <input type="datetime-local" class="form-control @error('pubblicato_dal') is-invalid @enderror"
                           id="pubblicato_dal" name="pubblicato_dal"
                           value="{{ old('pubblicato_dal', $annuncio->pubblicato_dal?->format('Y-m-d\TH:i')) }}">
                    <div class="form-text">Lascia vuoto per salvare come bozza.</div>
                    @error('pubblicato_dal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="pubblicato_al">Pubblicato fino al</label>
                    <input type="datetime-local" class="form-control @error('pubblicato_al') is-invalid @enderror"
                           id="pubblicato_al" name="pubblicato_al"
                           value="{{ old('pubblicato_al', $annuncio->pubblicato_al?->format('Y-m-d\TH:i')) }}">
                    <div class="form-text">Lascia vuoto per non far scadere l'annuncio.</div>
                    @error('pubblicato_al')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-check form-switch mb-2">
                <input type="hidden" name="in_evidenza" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="in_evidenza"
                       name="in_evidenza" value="1" @checked(old('in_evidenza', $annuncio->in_evidenza))>
                <label class="form-check-label" for="in_evidenza">In evidenza (mostrato per primo)</label>
            </div>

            <div class="form-check form-switch mb-3">
                <input type="hidden" name="solo_soci" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="solo_soci"
                       name="solo_soci" value="1" @checked(old('solo_soci', $annuncio->solo_soci))>
                <label class="form-check-label" for="solo_soci">
                    Solo soci (visibile solo nella bacheca dell'area riservata, non sul mini-sito pubblico)
                </label>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>{{ $isEdit ? 'Salva modifiche' : 'Crea annuncio' }}
            </button>
        </form>
    </div>
</div>
@endsection
