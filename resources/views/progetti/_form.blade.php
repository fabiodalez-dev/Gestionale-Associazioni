@csrf
@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $errore)
                <li>{{ $errore }}</li>
            @endforeach
        </ul>
    </div>
@endif

@php($progetto = $progetto ?? null)

<div class="mb-3">
    <label for="nome" class="form-label">Nome *</label>
    <input type="text" name="nome" id="nome" maxlength="200" required
           class="form-control @error('nome') is-invalid @enderror"
           value="{{ old('nome', $progetto?->nome) }}">
    @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="descrizione" class="form-label">Descrizione</label>
    <textarea name="descrizione" id="descrizione" rows="3" maxlength="5000"
              class="form-control @error('descrizione') is-invalid @enderror">{{ old('descrizione', $progetto?->descrizione) }}</textarea>
    @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row g-3">
    <div class="col-md-6 mb-3">
        <label for="dal" class="form-label">Data inizio</label>
        <input type="date" name="dal" id="dal"
               class="form-control @error('dal') is-invalid @enderror"
               value="{{ old('dal', $progetto?->dal?->format('Y-m-d')) }}">
        @error('dal')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="al" class="form-label">Data fine</label>
        <input type="date" name="al" id="al"
               class="form-control @error('al') is-invalid @enderror"
               value="{{ old('al', $progetto?->al?->format('Y-m-d')) }}">
        @error('al')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4 mb-3">
        <label for="stato" class="form-label">Stato *</label>
        <select name="stato" id="stato" class="form-select @error('stato') is-invalid @enderror" required>
            @foreach (\App\Models\Progetto::STATI as $stato)
                <option value="{{ $stato }}" @selected(old('stato', $progetto?->stato ?? \App\Models\Progetto::STATO_BOZZA) === $stato)>
                    {{ ucfirst($stato) }}
                </option>
            @endforeach
        </select>
        @error('stato')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="budget" class="form-label">Budget (&euro;)</label>
        <input type="number" step="0.01" min="0" name="budget" id="budget"
               class="form-control @error('budget') is-invalid @enderror"
               value="{{ old('budget', $progetto?->budget) }}" placeholder="Opzionale">
        @error('budget')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="responsabile_id" class="form-label">Responsabile</label>
        <select name="responsabile_id" id="responsabile_id" class="form-select @error('responsabile_id') is-invalid @enderror">
            <option value="">— Nessuno —</option>
            @foreach ($responsabili as $responsabile)
                <option value="{{ $responsabile->id }}" @selected(old('responsabile_id', $progetto?->responsabile_id) === $responsabile->id)>
                    {{ trim($responsabile->cognome.' '.$responsabile->nome) ?: $responsabile->email }}
                </option>
            @endforeach
        </select>
        @error('responsabile_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
