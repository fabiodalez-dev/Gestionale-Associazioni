{{-- Campi del form contatto esterno (f.21): creazione ($contatto NULL) o modifica. --}}
@php
    use App\Models\ContattoEsterno;
    /** @var \App\Models\ContattoEsterno|null $contatto */
    $prefix = $contatto?->id ?? 'new';
    $tagString = $contatto ? implode(', ', $contatto->tag ?? []) : old('tag');
@endphp

<div class="row g-2">
    <div class="col-12 col-md-5">
        <label class="form-label small mb-1" for="nome-{{ $prefix }}">Ragione sociale / Nome *</label>
        <input type="text" class="form-control form-control-sm @error('nome') is-invalid @enderror" id="nome-{{ $prefix }}"
               name="nome" maxlength="200" required value="{{ old('nome', $contatto?->nome) }}">
        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="tipo-{{ $prefix }}">Tipo *</label>
        <select class="form-select form-select-sm @error('tipo') is-invalid @enderror" id="tipo-{{ $prefix }}" name="tipo" required>
            @foreach (ContattoEsterno::TIPI as $valore)
                <option value="{{ $valore }}" @selected(old('tipo', $contatto?->tipo) === $valore)>{{ ucfirst($valore) }}</option>
            @endforeach
        </select>
        @error('tipo')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label small mb-1" for="referente-{{ $prefix }}">Referente</label>
        <input type="text" class="form-control form-control-sm @error('referente') is-invalid @enderror" id="referente-{{ $prefix }}"
               name="referente" maxlength="150" value="{{ old('referente', $contatto?->referente) }}">
        @error('referente')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="email-{{ $prefix }}">Email</label>
        <input type="email" class="form-control form-control-sm @error('email') is-invalid @enderror" id="email-{{ $prefix }}"
               name="email" maxlength="255" value="{{ old('email', $contatto?->email) }}">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="telefono-{{ $prefix }}">Telefono</label>
        <input type="text" class="form-control form-control-sm @error('telefono') is-invalid @enderror" id="telefono-{{ $prefix }}"
               name="telefono" maxlength="30" value="{{ old('telefono', $contatto?->telefono) }}">
        @error('telefono')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label small mb-1" for="tag-{{ $prefix }}">Tag (separati da virgola)</label>
        <input type="text" class="form-control form-control-sm @error('tag') is-invalid @enderror" id="tag-{{ $prefix }}"
               name="tag" maxlength="500" value="{{ $tagString }}" placeholder="es. bando, comune, materiale">
        @error('tag')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label class="form-label small mb-1" for="note-{{ $prefix }}">Note</label>
        <textarea class="form-control form-control-sm @error('note') is-invalid @enderror" id="note-{{ $prefix }}"
                  name="note" rows="2" maxlength="5000">{{ old('note', $contatto?->note) }}</textarea>
        @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
