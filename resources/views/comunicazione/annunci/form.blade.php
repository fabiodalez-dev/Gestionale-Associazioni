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

            @php $tipoCorrente = old('tipo', $annuncio->tipo ?? \App\Models\Annuncio::TIPO_COMUNICAZIONE); @endphp
            <div class="mb-3">
                <label class="form-label" for="tipo">Tipo *</label>
                <select class="form-select @error('tipo') is-invalid @enderror" id="tipo" name="tipo" required>
                    <option value="{{ \App\Models\Annuncio::TIPO_COMUNICAZIONE }}" @selected($tipoCorrente === \App\Models\Annuncio::TIPO_COMUNICAZIONE)>Comunicazione</option>
                    <option value="{{ \App\Models\Annuncio::TIPO_CONVOCAZIONE }}" @selected($tipoCorrente === \App\Models\Annuncio::TIPO_CONVOCAZIONE)>Convocazione assemblea</option>
                </select>
                @error('tipo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label" for="corpo_html">Contenuto *</label>
                <textarea class="form-control @error('corpo_html') is-invalid @enderror"
                          id="corpo_html" name="corpo_html" rows="10" required>{{ old('corpo_html', $annuncio->corpo_html) }}</textarea>
                <div class="form-text">HTML semplice consentito (paragrafi, liste, link, immagini): viene ripulito al salvataggio.</div>
                @error('corpo_html')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Dettagli convocazione assemblea (visibili solo per tipo 'convocazione') --}}
            <div id="campiConvocazione" class="border rounded p-3 mb-3 {{ $tipoCorrente === \App\Models\Annuncio::TIPO_CONVOCAZIONE ? '' : 'd-none' }}">
                <h2 class="h6 mb-3"><i class="bi bi-people me-1"></i>Dettagli assemblea</h2>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="data_assemblea">Data e ora assemblea *</label>
                        <input type="datetime-local" class="form-control @error('data_assemblea') is-invalid @enderror"
                               id="data_assemblea" name="data_assemblea"
                               value="{{ old('data_assemblea', $annuncio->data_assemblea?->format('Y-m-d\TH:i')) }}">
                        @error('data_assemblea')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="luogo_assemblea">Luogo</label>
                        <input type="text" class="form-control @error('luogo_assemblea') is-invalid @enderror"
                               id="luogo_assemblea" name="luogo_assemblea" maxlength="255"
                               value="{{ old('luogo_assemblea', $annuncio->luogo_assemblea) }}">
                        @error('luogo_assemblea')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ordine_del_giorno">Ordine del giorno</label>
                    <textarea class="form-control @error('ordine_del_giorno') is-invalid @enderror"
                              id="ordine_del_giorno" name="ordine_del_giorno" rows="5">{{ old('ordine_del_giorno', $annuncio->ordine_del_giorno) }}</textarea>
                    <div class="form-text">Un punto per riga.</div>
                    @error('ordine_del_giorno')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-0">
                    <label class="form-label" for="evento_id">Evento collegato (opzionale)</label>
                    <select class="form-select @error('evento_id') is-invalid @enderror" id="evento_id" name="evento_id">
                        <option value="">— Nessun evento —</option>
                        @foreach ($eventiFuturi ?? [] as $evento)
                            <option value="{{ $evento->id }}" @selected(old('evento_id', $annuncio->evento_id) === $evento->id)>
                                {{ $evento->titolo }} ({{ $evento->data_evento?->format('d/m/Y H:i') }})
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Se colleghi un evento pubblicato, i soci potranno confermare la presenza.</div>
                    @error('evento_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
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

@push('scripts')
<script>
    // Mostra/nasconde i dettagli assemblea in base al tipo selezionato
    (function () {
        var tipo = document.getElementById('tipo');
        var campi = document.getElementById('campiConvocazione');
        if (!tipo || !campi) return;
        tipo.addEventListener('change', function () {
            campi.classList.toggle('d-none', tipo.value !== 'convocazione');
        });
    })();
</script>
@endpush
@endsection
