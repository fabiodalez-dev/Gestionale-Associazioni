{{-- Campi del form sponsor (f.22): creazione ($sponsorCorrente NULL) o modifica. --}}
@php
    use App\Models\Sponsor;
    /** @var \App\Models\Sponsor|null $sponsorCorrente */
    $prefix = $sponsorCorrente?->id ?? 'new';
@endphp

<div class="row g-2">
    <div class="col-12 col-md-5">
        <label class="form-label small mb-1" for="nome-{{ $prefix }}">Nome *</label>
        <input type="text" class="form-control form-control-sm @error('nome') is-invalid @enderror" id="nome-{{ $prefix }}"
               name="nome" maxlength="200" required value="{{ old('nome', $sponsorCorrente?->nome) }}">
        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="livello-{{ $prefix }}">Livello *</label>
        <select class="form-select form-select-sm @error('livello') is-invalid @enderror" id="livello-{{ $prefix }}" name="livello" required>
            @foreach (Sponsor::LIVELLI as $valore)
                <option value="{{ $valore }}" @selected(old('livello', $sponsorCorrente?->livello ?? Sponsor::LIVELLO_PARTNER) === $valore)>{{ ucfirst($valore) }}</option>
            @endforeach
        </select>
        @error('livello')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label small mb-1" for="contatto-{{ $prefix }}">Scheda CRM collegata</label>
        <select class="form-select form-select-sm @error('contatto_esterno_id') is-invalid @enderror" id="contatto-{{ $prefix }}" name="contatto_esterno_id">
            <option value="">— Nessuna —</option>
            @foreach ($contatti as $c)
                <option value="{{ $c->id }}" @selected(old('contatto_esterno_id', $sponsorCorrente?->contatto_esterno_id) === $c->id)>{{ $c->nome }}</option>
            @endforeach
        </select>
        @error('contatto_esterno_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="logo-{{ $prefix }}">URL logo</label>
        <input type="url" class="form-control form-control-sm @error('logo_url') is-invalid @enderror" id="logo-{{ $prefix }}"
               name="logo_url" maxlength="500" value="{{ old('logo_url', $sponsorCorrente?->logo_url) }}" placeholder="https://...">
        @error('logo_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="sito-{{ $prefix }}">URL sito</label>
        <input type="url" class="form-control form-control-sm @error('sito_url') is-invalid @enderror" id="sito-{{ $prefix }}"
               name="sito_url" maxlength="500" value="{{ old('sito_url', $sponsorCorrente?->sito_url) }}" placeholder="https://...">
        @error('sito_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="dal-{{ $prefix }}">Dal</label>
        <input type="date" class="form-control form-control-sm @error('dal') is-invalid @enderror" id="dal-{{ $prefix }}"
               name="dal" value="{{ old('dal', $sponsorCorrente?->dal?->toDateString()) }}">
        @error('dal')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="al-{{ $prefix }}">Al</label>
        <input type="date" class="form-control form-control-sm @error('al') is-invalid @enderror" id="al-{{ $prefix }}"
               name="al" value="{{ old('al', $sponsorCorrente?->al?->toDateString()) }}">
        @error('al')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-6 d-flex align-items-end">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="visibile-{{ $prefix }}" name="visibile_sul_sito"
                   @checked(old('visibile_sul_sito', $sponsorCorrente?->visibile_sul_sito))>
            <label class="form-check-label small" for="visibile-{{ $prefix }}">
                Mostra in "I nostri sostenitori" sulla home pubblica
            </label>
        </div>
    </div>
</div>
