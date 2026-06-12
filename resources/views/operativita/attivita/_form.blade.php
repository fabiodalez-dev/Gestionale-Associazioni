{{-- Campi del form attività admin (f.4): usato sia per la creazione sia per
     la modifica inline ($attivitaCorrente NULL = creazione). --}}
@php
    /** @var \App\Models\AttivitaAdmin|null $attivitaCorrente */
    $prefix = $attivitaCorrente?->id ?? 'new';
    $collegamentoCorrente = match ($attivitaCorrente?->notabile_type) {
        \App\Models\Socio::class => 'socio',
        \App\Models\Evento::class => 'evento',
        default => 'nessuno',
    };
@endphp

<div class="row g-2">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="titolo-{{ $prefix }}">Titolo *</label>
        <input type="text" class="form-control form-control-sm @error('titolo') is-invalid @enderror" id="titolo-{{ $prefix }}"
               name="titolo" maxlength="255" required value="{{ old('titolo', $attivitaCorrente?->titolo) }}">
        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="scadenza-{{ $prefix }}">Scadenza</label>
        <input type="date" class="form-control form-control-sm @error('scadenza') is-invalid @enderror" id="scadenza-{{ $prefix }}"
               name="scadenza" value="{{ old('scadenza', $attivitaCorrente?->scadenza?->toDateString()) }}">
        @error('scadenza')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="assegnatario-{{ $prefix }}">Assegnatario</label>
        <select class="form-select form-select-sm @error('assegnatario_id') is-invalid @enderror" id="assegnatario-{{ $prefix }}" name="assegnatario_id">
            <option value="">— Nessuno —</option>
            @foreach ($utenti as $u)
                <option value="{{ $u->id }}" @selected(old('assegnatario_id', $attivitaCorrente?->assegnatario_id) === $u->id)>
                    {{ $u->nome_completo ?: $u->email }}
                </option>
            @endforeach
        </select>
        @error('assegnatario_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label small mb-1" for="descrizione-{{ $prefix }}">Descrizione</label>
        <input type="text" class="form-control form-control-sm @error('descrizione') is-invalid @enderror" id="descrizione-{{ $prefix }}"
               name="descrizione" maxlength="5000" value="{{ old('descrizione', $attivitaCorrente?->descrizione) }}">
        @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="collegamento-{{ $prefix }}">Collegata a</label>
        <select class="form-select form-select-sm" id="collegamento-{{ $prefix }}" name="collegamento">
            <option value="nessuno" @selected(old('collegamento', $collegamentoCorrente) === 'nessuno')>— Niente —</option>
            <option value="socio" @selected(old('collegamento', $collegamentoCorrente) === 'socio')>Socio</option>
            <option value="evento" @selected(old('collegamento', $collegamentoCorrente) === 'evento')>Evento</option>
        </select>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label small mb-1" for="socio-{{ $prefix }}">Socio</label>
        <select class="form-select form-select-sm @error('socio_id') is-invalid @enderror" id="socio-{{ $prefix }}" name="socio_id">
            <option value="">— Seleziona socio —</option>
            @foreach ($sociAttivi as $s)
                <option value="{{ $s->id }}" @selected(old('socio_id', $collegamentoCorrente === 'socio' ? $attivitaCorrente?->notabile_id : null) === $s->id)>
                    {{ $s->cognome }} {{ $s->nome }} (n. {{ $s->numero_socio }})
                </option>
            @endforeach
        </select>
        @error('socio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label small mb-1" for="evento-{{ $prefix }}">Evento</label>
        <select class="form-select form-select-sm @error('evento_id') is-invalid @enderror" id="evento-{{ $prefix }}" name="evento_id">
            <option value="">— Seleziona evento —</option>
            @foreach ($eventi as $e)
                <option value="{{ $e->id }}" @selected(old('evento_id', $collegamentoCorrente === 'evento' ? $attivitaCorrente?->notabile_id : null) === $e->id)>
                    {{ $e->titolo }}{{ $e->data_evento ? ' — '.$e->data_evento->format('d/m/Y') : '' }}
                </option>
            @endforeach
        </select>
        @error('evento_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
