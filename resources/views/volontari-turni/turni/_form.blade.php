@php($t = $turno ?? null)
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label" for="titolo">Titolo *</label>
        <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="titolo" name="titolo"
               value="{{ old('titolo', $t->titolo ?? '') }}" maxlength="200" required>
        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="posti">Posti *</label>
        <input type="number" class="form-control @error('posti') is-invalid @enderror" id="posti" name="posti"
               value="{{ old('posti', $t->posti ?? 1) }}" min="1" max="999" required>
        @error('posti')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="data">Data *</label>
        <input type="date" class="form-control @error('data') is-invalid @enderror" id="data" name="data"
               value="{{ old('data', optional($t?->data)->format('Y-m-d')) }}" required>
        @error('data')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="ora_inizio">Ora inizio *</label>
        <input type="time" class="form-control @error('ora_inizio') is-invalid @enderror" id="ora_inizio" name="ora_inizio"
               value="{{ old('ora_inizio', $t ? substr((string) $t->ora_inizio, 0, 5) : '') }}" required>
        @error('ora_inizio')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="ora_fine">Ora fine *</label>
        <input type="time" class="form-control @error('ora_fine') is-invalid @enderror" id="ora_fine" name="ora_fine"
               value="{{ old('ora_fine', $t ? substr((string) $t->ora_fine, 0, 5) : '') }}" required>
        @error('ora_fine')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="mansione_id">Mansione richiesta</label>
        <select class="form-select @error('mansione_id') is-invalid @enderror" id="mansione_id" name="mansione_id">
            <option value="">— Nessuna —</option>
            @foreach ($mansioni as $m)
                <option value="{{ $m->id }}" @selected(old('mansione_id', $t->mansione_id ?? '') === $m->id)>{{ $m->nome }}</option>
            @endforeach
        </select>
        @error('mansione_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="evento_id">Evento collegato</label>
        <select class="form-select @error('evento_id') is-invalid @enderror" id="evento_id" name="evento_id">
            <option value="">— Nessuno —</option>
            @foreach ($eventi as $e)
                <option value="{{ $e->id }}" @selected(old('evento_id', $t->evento_id ?? '') === $e->id)>{{ $e->titolo }} ({{ optional($e->data_evento)->format('d/m/Y') }})</option>
            @endforeach
        </select>
        @error('evento_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="spazio_id">Spazio</label>
        <select class="form-select @error('spazio_id') is-invalid @enderror" id="spazio_id" name="spazio_id">
            <option value="">— Nessuno —</option>
            @foreach ($spazi as $s)
                <option value="{{ $s->id }}" @selected(old('spazio_id', $t->spazio_id ?? '') === $s->id)>{{ $s->nome }}</option>
            @endforeach
        </select>
        @error('spazio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    {{-- f.14 piano "Organizzazione 3": progetto collegato al turno (opzionale).
         Lista risolta inline (global scope sul tenant) se non passata dal controller. --}}
    @php($progettiTurno = $progetti ?? \App\Models\Progetto::query()->orderBy('nome')->get(['id', 'nome']))
    <div class="col-md-4">
        <label class="form-label" for="progetto_id">Progetto</label>
        <select class="form-select @error('progetto_id') is-invalid @enderror" id="progetto_id" name="progetto_id">
            <option value="">— Nessuno —</option>
            @foreach ($progettiTurno as $p)
                <option value="{{ $p->id }}" @selected(old('progetto_id', $t->progetto_id ?? '') === $p->id)>{{ $p->nome }}</option>
            @endforeach
        </select>
        @error('progetto_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label" for="note">Note</label>
        <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000">{{ old('note', $t->note ?? '') }}</textarea>
    </div>

    <div class="col-md-6">
        <div class="form-check">
            <input type="hidden" name="auto_conferma" value="0">
            <input type="checkbox" class="form-check-input" id="auto_conferma" name="auto_conferma" value="1"
                   @checked(old('auto_conferma', $t->auto_conferma ?? false))>
            <label class="form-check-label" for="auto_conferma">Auto-conferma le candidature dei soci</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="stato" name="stato" value="{{ \App\Models\Turno::STATO_CHIUSO }}"
                   @checked(old('stato', $t->stato ?? \App\Models\Turno::STATO_APERTO) === \App\Models\Turno::STATO_CHIUSO)>
            <label class="form-check-label" for="stato">Turno chiuso (non accetta candidature)</label>
        </div>
    </div>
</div>
