@extends('layouts.app')

@section('title', $pagina->exists ? 'Modifica — '.$pagina->titolo : 'Nuova pagina del manuale')

@section('content')
<div class="pt-3 pb-2 mb-3 border-bottom">
    <a href="{{ $pagina->exists ? route('manuale.show', $pagina) : route('manuale.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>{{ $pagina->exists ? $pagina->titolo : 'Manuale operativo' }}</a>
    <h1 class="h2 mb-0">{{ $pagina->exists ? 'Modifica pagina' : 'Nuova pagina' }}</h1>
</div>

@include('partials.flash')

<form method="POST" action="{{ $pagina->exists ? route('manuale.update', $pagina) : route('manuale.store') }}" class="row g-4">
    @csrf
    @if ($pagina->exists) @method('PUT') @endif

    <div class="col-lg-8">
        <div class="mb-3">
            <label class="form-label" for="manTitolo">Titolo <span class="text-danger">*</span></label>
            <input type="text" name="titolo" id="manTitolo" class="form-control @error('titolo') is-invalid @enderror" maxlength="200" value="{{ old('titolo', $pagina->titolo) }}" required>
            @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label" for="manContenuto">Procedura</label>
            <textarea name="contenuto" id="manContenuto" class="form-control font-monospace" rows="14">{{ old('contenuto', $pagina->contenuto) }}</textarea>
            <div class="form-text">Formattazione: <code>## Titoletto</code>, <code>- elenco</code>, <code>**grassetto**</code>, <code>[testo](https://link)</code>. Una riga vuota separa i paragrafi.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="manPassi">Passi della checklist</label>
            <textarea name="passi" id="manPassi" class="form-control" rows="6" placeholder="Un passo per riga">{{ old('passi', implode("\n", $pagina->elencoPassi())) }}</textarea>
            <div class="form-text">Un passo per riga. Dalla pagina potrai avviare la checklist e spuntare i passi man mano.</div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="mb-3">
            <label class="form-label" for="manSezione">Sezione <span class="text-danger">*</span></label>
            <input type="text" name="sezione" id="manSezione" class="form-control" list="sezioniManuale" maxlength="100" value="{{ old('sezione', $pagina->sezione) }}" placeholder="Es. Tesseramento" required>
            <datalist id="sezioniManuale">
                @foreach ($sezioni as $sezione)<option value="{{ $sezione }}">@endforeach
            </datalist>
        </div>
        <div class="mb-3">
            <label class="form-label" for="manVisibilita">Chi la vede</label>
            <select name="visibilita" id="manVisibilita" class="form-select">
                @foreach (\App\Models\ManualePagina::VISIBILITA as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected(old('visibilita', $pagina->visibilita) === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="manResp">Responsabile della pagina</label>
            <select name="responsabile_utente_id" id="manResp" class="form-select">
                <option value="">— Nessuno —</option>
                @foreach ($utenti as $utente)
                    <option value="{{ $utente->id }}" @selected(old('responsabile_utente_id', $pagina->responsabile_utente_id) === $utente->id)>{{ $utente->nome_completo }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="manOrdine">Ordine nella sezione</label>
            <input type="number" name="ordine" id="manOrdine" class="form-control" min="0" max="1000" value="{{ old('ordine', $pagina->ordine ?? 0) }}">
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Salva</button>
    </div>
</form>
@endsection
