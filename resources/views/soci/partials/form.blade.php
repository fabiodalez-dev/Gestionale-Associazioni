{{-- Form socio condiviso create/edit (spec soci §1.3/§1.4, §5, §6, §8)
     Variabili: $socio (nullable), $sedi, $tipiSocio, $categorie, $tags,
     $campiPersonalizzati, $campiObbligatori, $stati, $valoriCampi?, $tagSelezionati? --}}
@php
    $isEdit = isset($socio);
    $valoriCampi = $valoriCampi ?? collect();
    $tagSelezionati = old('tag_ids', $tagSelezionati ?? []);
    $req = fn (string $campo) => ($campiObbligatori[$campo] ?? false);
    $star = fn (string $campo) => $req($campo) ? ' *' : '';
@endphp

<div class="card mb-3">
    <div class="card-header bg-white"><i class="bi bi-person-vcard me-1"></i>Anagrafica</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label" for="numero_socio">Numero Socio{{ $isEdit ? ' *' : '' }}</label>
                <input type="text" class="form-control" id="numero_socio" name="numero_socio"
                       value="{{ old('numero_socio', $socio->numero_socio ?? '') }}"
                       @if (! $isEdit) disabled placeholder="Verrà assegnato automaticamente" @else required @endif>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="nome">Nome *</label>
                <input type="text" class="form-control" id="nome" name="nome" maxlength="100" required
                       value="{{ old('nome', $socio->nome ?? '') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="cognome">Cognome *</label>
                <input type="text" class="form-control" id="cognome" name="cognome" maxlength="100" required
                       value="{{ old('cognome', $socio->cognome ?? '') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="email">Email *</label>
                <input type="email" class="form-control" id="email" name="email" required
                       value="{{ old('email', $socio->email ?? '') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="codice_fiscale">Codice Fiscale{{ $star('codice_fiscale') }}</label>
                <input type="text" class="form-control text-uppercase" id="codice_fiscale" name="codice_fiscale"
                       maxlength="16" @required($req('codice_fiscale'))
                       value="{{ old('codice_fiscale', $socio->codice_fiscale ?? '') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="data_nascita">Data di Nascita{{ $star('data_nascita') }}</label>
                <input type="date" class="form-control" id="data_nascita" name="data_nascita" @required($req('data_nascita'))
                       value="{{ old('data_nascita', $socio?->data_nascita?->toDateString() ?? '') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="data_iscrizione">Data Iscrizione *</label>
                <input type="date" class="form-control" id="data_iscrizione" name="data_iscrizione" required
                       value="{{ old('data_iscrizione', $socio?->data_iscrizione?->toDateString() ?? now()->toDateString()) }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="stato">Stato *</label>
                <select class="form-select" id="stato" name="stato" required>
                    @foreach ($stati as $stato)
                        <option value="{{ $stato }}" @selected(old('stato', $socio->stato ?? 'Attivo') === $stato)>{{ $stato }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="telefono">Telefono{{ $star('telefono') }}</label>
                <input type="tel" class="form-control" id="telefono" name="telefono" maxlength="20" @required($req('telefono'))
                       value="{{ old('telefono', $socio->telefono ?? '') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="indirizzo">Indirizzo{{ $star('indirizzo') }}</label>
                <input type="text" class="form-control" id="indirizzo" name="indirizzo" @required($req('indirizzo'))
                       value="{{ old('indirizzo', $socio->indirizzo ?? '') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="citta">Città{{ $star('citta') }}</label>
                <input type="text" class="form-control" id="citta" name="citta" maxlength="100" @required($req('citta'))
                       value="{{ old('citta', $socio->citta ?? '') }}">
            </div>
            <div class="col-md-1">
                <label class="form-label" for="provincia">Prov.{{ $star('provincia') }}</label>
                <input type="text" class="form-control text-uppercase" id="provincia" name="provincia" maxlength="2" @required($req('provincia'))
                       value="{{ old('provincia', $socio->provincia ?? '') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="cap">CAP{{ $star('cap') }}</label>
                <input type="text" class="form-control" id="cap" name="cap" maxlength="5" @required($req('cap'))
                       value="{{ old('cap', $socio->cap ?? '') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="tipo_socio_id">Tipo Socio{{ $star('tipo_socio_id') }}</label>
                <select class="form-select" id="tipo_socio_id" name="tipo_socio_id" @required($req('tipo_socio_id'))>
                    <option value="">— Nessuno —</option>
                    @foreach ($tipiSocio as $tipo)
                        <option value="{{ $tipo->id }}" @selected(old('tipo_socio_id', $socio->tipo_socio_id ?? '') === $tipo->id)>{{ $tipo->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="categoria_socio_id">Categoria Socio{{ $star('categoria_socio_id') }}</label>
                <select class="form-select" id="categoria_socio_id" name="categoria_socio_id" @required($req('categoria_socio_id'))>
                    <option value="">— Nessuna —</option>
                    @foreach ($categorie as $categoria)
                        <option value="{{ $categoria->id }}" @selected(old('categoria_socio_id', $socio->categoria_socio_id ?? '') === $categoria->id)>{{ $categoria->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="sede_id">Sede{{ $star('sede_id') }}</label>
                <select class="form-select" id="sede_id" name="sede_id" @required($req('sede_id'))>
                    <option value="">— Nessuna —</option>
                    @foreach ($sedi as $sede)
                        <option value="{{ $sede->id }}" @selected(old('sede_id', $socio->sede_id ?? '') === $sede->id)>{{ $sede->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="note">Note{{ $star('note') }}</label>
                <textarea class="form-control" id="note" name="note" rows="2" @required($req('note'))>{{ old('note', $socio->note ?? '') }}</textarea>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="privacy_consenso" name="privacy_consenso" value="1"
                           @checked(old('privacy_consenso', $socio->privacy_consenso ?? false)) @required($req('privacy_consenso'))>
                    <label class="form-check-label" for="privacy_consenso">Consenso Privacy{{ $star('privacy_consenso') }}</label>
                </div>
            </div>
            @if (! $isEdit)
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="genera_tessera" name="genera_tessera" value="1"
                               @checked(old('genera_tessera', true))>
                        <label class="form-check-label" for="genera_tessera">Genera tessera per l'anno corrente</label>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Tutore per soci minorenni (f.22 piano "Gestione 2" — modulo membri):
     mostrato/obbligatorio dinamicamente quando la data di nascita indica età < 18 --}}
<div class="card mb-3 {{ ($socio ?? null)?->isMinorenne() || filled(old('tutore_nome', $socio->tutore_nome ?? null)) ? '' : 'd-none' }}" id="cardTutore">
    <div class="card-header bg-white">
        <i class="bi bi-person-exclamation me-1"></i>Tutore
        <span class="badge bg-info text-dark ms-1">Socio minorenne</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Il socio è minorenne: nome, telefono ed email del tutore sono obbligatori.</p>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="tutore_nome">Nome del tutore <span class="tutore-req">*</span></label>
                <input type="text" class="form-control" id="tutore_nome" name="tutore_nome" maxlength="150"
                       value="{{ old('tutore_nome', $socio->tutore_nome ?? '') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="tutore_telefono">Telefono del tutore <span class="tutore-req">*</span></label>
                <input type="tel" class="form-control" id="tutore_telefono" name="tutore_telefono" maxlength="30"
                       value="{{ old('tutore_telefono', $socio->tutore_telefono ?? '') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="tutore_email">Email del tutore <span class="tutore-req">*</span></label>
                <input type="email" class="form-control" id="tutore_email" name="tutore_email" maxlength="255"
                       value="{{ old('tutore_email', $socio->tutore_email ?? '') }}">
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var dataNascita = document.getElementById('data_nascita');
    var card = document.getElementById('cardTutore');
    if (!dataNascita || !card) return;

    var campi = ['tutore_nome', 'tutore_telefono', 'tutore_email'].map(function (id) {
        return document.getElementById(id);
    });

    function isMinorenne() {
        if (!dataNascita.value) return false;
        var nascita = new Date(dataNascita.value);
        if (isNaN(nascita.getTime())) return false;
        var oggi = new Date();
        var eta = oggi.getFullYear() - nascita.getFullYear();
        var m = oggi.getMonth() - nascita.getMonth();
        if (m < 0 || (m === 0 && oggi.getDate() < nascita.getDate())) eta--;
        return eta < 18;
    }

    function aggiorna() {
        var minore = isMinorenne();
        var haValori = campi.some(function (c) { return c && c.value.trim() !== ''; });
        card.classList.toggle('d-none', !minore && !haValori);
        campi.forEach(function (c) { if (c) c.required = minore; });
    }

    dataNascita.addEventListener('change', aggiorna);
    dataNascita.addEventListener('input', aggiorna);
    aggiorna();
})();
</script>
@endpush

@if ($campiPersonalizzati->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header bg-white"><i class="bi bi-journal-plus me-1"></i>Dati Personalizzati</div>
        <div class="card-body">
            <div class="row g-3">
                @foreach ($campiPersonalizzati as $campo)
                    @php
                        $valore = (string) old("custom_field.{$campo->id}", $valoriCampi[$campo->id] ?? '');
                        $name = "custom_field[{$campo->id}]";
                        $chiaveErrore = "custom_field.{$campo->id}";
                        $classeErrore = $errors->has($chiaveErrore) ? ' is-invalid' : '';
                        $opzioniSelect = array_values(array_filter(array_map('trim', explode("\n", (string) $campo->opzioni))));
                        // Valore salvato prima che l'opzione fosse rinominata o rimossa: resta visibile e selezionato
                        $valoreFuoriOpzioni = $campo->tipo_campo === 'select' && $valore !== '' && ! in_array($valore, $opzioniSelect, true);
                    @endphp
                    <div class="col-md-4">
                        <label class="form-label" for="campo_{{ $campo->id }}">
                            {{ $campo->nome_campo }}{{ $campo->obbligatorio ? ' *' : '' }}
                        </label>
                        @if ($campo->tipo_campo === 'textarea')
                            <textarea class="form-control{{ $classeErrore }}" id="campo_{{ $campo->id }}" name="{{ $name }}" rows="2"
                                      @required($campo->obbligatorio)>{{ $valore }}</textarea>
                        @elseif ($campo->tipo_campo === 'select')
                            <select class="form-select{{ $classeErrore }}" id="campo_{{ $campo->id }}" name="{{ $name }}" @required($campo->obbligatorio)>
                                <option value="">— Seleziona —</option>
                                @foreach ($opzioniSelect as $opzione)
                                    <option value="{{ $opzione }}" @selected($valore === $opzione)>{{ $opzione }}</option>
                                @endforeach
                                @if ($valoreFuoriOpzioni)
                                    <option value="{{ $valore }}" selected>{{ $valore }} (valore salvato, non più tra le opzioni)</option>
                                @endif
                            </select>
                        @elseif ($campo->tipo_campo === 'checkbox')
                            <div class="form-check">
                                <input class="form-check-input{{ $classeErrore }}" type="checkbox" id="campo_{{ $campo->id }}"
                                       name="{{ $name }}" value="1" @checked($valore === '1')>
                            </div>
                        @else
                            <input type="{{ $campo->tipo_campo }}" class="form-control{{ $classeErrore }}" id="campo_{{ $campo->id }}"
                                   name="{{ $name }}" value="{{ $valore }}" @required($campo->obbligatorio)>
                        @endif
                        @error($chiaveErrore)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @if ($campo->descrizione)
                            <div class="form-text">{{ $campo->descrizione }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@if ($tags->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header bg-white"><i class="bi bi-tags me-1"></i>Tag</div>
        <div class="card-body">
            @foreach ($tags as $tag)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="tag_{{ $tag->id }}" name="tag_ids[]"
                           value="{{ $tag->id }}" @checked(in_array($tag->id, (array) $tagSelezionati, true))>
                    <label class="form-check-label" for="tag_{{ $tag->id }}">
                        <span class="badge" style="background-color: {{ $tag->colore }}">{{ $tag->nome_tag }}</span>
                    </label>
                </div>
            @endforeach
        </div>
    </div>
@endif
