@php
    $mod = $associazione->moduli();
    $campiPers = \App\Models\CampoPersonalizzato::query()->forAssociazione($associazione->id)->orderBy('ordine')->orderBy('nome_campo')->get(['id', 'nome_campo']);
    $etichetteCampi = \App\Services\Struttura\RequiredFieldsService::LABELS;
    $righeConsensi = array_merge($mod->elencoConsensi(), array_fill(0, 3, ['chiave' => '', 'testo' => '', 'obbligatorio' => false, 'link_url' => null]));
@endphp
<form method="POST" action="{{ route('configurazioni.moduli') }}">
    @csrf
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-palette me-2"></i>Aspetto</span>
            <a class="small" href="{{ route('preiscrizione.show', ['associazione' => $associazione->id]) }}" target="_blank" rel="noopener">
                Apri il modulo di pre-iscrizione <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
        <div class="card-body">
            <p class="text-muted small">Valgono per il modulo di pre-iscrizione, l'iscrizione online, la pagina della privacy e il mini-sito.</p>
            <div class="row g-3 align-items-end">
                <div class="col-sm-4">
                    <label class="form-label" for="mod_colore">Colore principale</label>
                    <input type="color" class="form-control form-control-color w-100 @error('colore_primario') is-invalid @enderror" id="mod_colore" name="colore_primario" value="{{ old('colore_primario', $mod->colore_primario) }}">
                    @error('colore_primario')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-8">
                    <div class="form-check form-switch">
                        <input type="hidden" name="mostra_logo" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="mod_logo" name="mostra_logo" value="1" @checked(old('mostra_logo', $mod->mostra_logo))>
                        <label class="form-check-label" for="mod_logo">Mostra il logo dell'associazione</label>
                    </div>
                    @unless ($associazione->logoUrl())
                        <div class="form-text"><a href="{{ route('configurazioni.index', ['section' => 'logo']) }}">Carica il logo</a> per mostrarlo nei moduli.</div>
                    @endunless
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-card-text me-2"></i>Testi della pre-iscrizione</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="mod_titolo">Titolo</label>
                <input type="text" class="form-control" id="mod_titolo" name="titolo_preiscrizione" maxlength="150" value="{{ old('titolo_preiscrizione', $mod->titolo_preiscrizione) }}" placeholder="Richiesta di pre-iscrizione">
            </div>
            <div class="mb-3">
                <label class="form-label" for="mod_intro">Testo introduttivo</label>
                <textarea class="form-control" id="mod_intro" name="intro_preiscrizione_html" rows="4" maxlength="5000" placeholder="Benvenuto! Compila il modulo per chiedere l'iscrizione…">{{ old('intro_preiscrizione_html', $mod->intro_preiscrizione_html) }}</textarea>
                <div class="form-text">Ammessi grassetto, corsivo, elenchi e link; il resto viene rimosso.</div>
            </div>
            <div>
                <label class="form-label" for="mod_conferma">Messaggio dopo l'invio</label>
                <input type="text" class="form-control" id="mod_conferma" name="messaggio_conferma" maxlength="500" value="{{ old('messaggio_conferma', $mod->messaggio_conferma) }}" placeholder="La tua richiesta è stata inviata con successo! Riceverai una comunicazione quando sarà approvata.">
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-ui-checks me-2"></i>Campi della pre-iscrizione</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Nome, cognome ed email sono sempre presenti. Quali campi sono obbligatori si decide in <a href="{{ route('configurazioni.index', ['section' => 'campi-obbligatori']) }}">Campi obbligatori</a>.</p>
            <div class="d-flex flex-wrap gap-3 mb-3">
                @foreach (\App\Settings\ModuliSettings::CAMPI_NASCONDIBILI as $campo)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="campi_visibili[]" value="{{ $campo }}" id="mod_campo_{{ $campo }}" @checked($mod->campoVisibile($campo))>
                        <label class="form-check-label" for="mod_campo_{{ $campo }}">{{ $etichetteCampi[$campo] ?? $campo }}</label>
                    </div>
                @endforeach
            </div>
            @if ($campiPers->isNotEmpty())
                <h3 class="h6 mt-2">Campi personalizzati</h3>
                <div class="d-flex flex-wrap gap-3">
                    @foreach ($campiPers as $campoPers)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="campi_personalizzati[]" value="{{ $campoPers->id }}" id="mod_cp_{{ $campoPers->id }}"
                                   @checked($mod->campi_personalizzati_preiscrizione === null || in_array($campoPers->id, $mod->campi_personalizzati_preiscrizione, true))>
                            <label class="form-check-label" for="mod_cp_{{ $campoPers->id }}">{{ $campoPers->nome_campo }}</label>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="form-text mt-2">Per aggiungere nuovi campi: <a href="{{ route('configurazioni.index', ['section' => 'campi-obbligatori']) }}">campi personalizzati</a>.</div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-check2-square me-2"></i>Consensi aggiuntivi</div>
        <div class="card-body">
            <p class="text-muted small">Caselle mostrate sotto il consenso privacy nella pre-iscrizione e nell'iscrizione online (es. newsletter, uso delle foto). Ogni risposta viene registrata con il testo esatto, la data e l'indirizzo IP. Per toglierne una, svuota il testo.</p>
            @error('consensi')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
            @foreach ($righeConsensi as $i => $consenso)
                <div class="row g-2 align-items-center mb-2">
                    <input type="hidden" name="consensi[{{ $i }}][chiave]" value="{{ $consenso['chiave'] }}">
                    <div class="col-md-6">
                        <label class="visually-hidden" for="mod_cons_{{ $i }}">Testo del consenso {{ $i + 1 }}</label>
                        <input type="text" class="form-control form-control-sm" id="mod_cons_{{ $i }}" name="consensi[{{ $i }}][testo]" maxlength="500" value="{{ old('consensi.'.$i.'.testo', $consenso['testo']) }}" placeholder="Es. Acconsento a ricevere la newsletter dell'associazione">
                    </div>
                    <div class="col-md-4">
                        <label class="visually-hidden" for="mod_link_{{ $i }}">Link di approfondimento</label>
                        <input type="url" class="form-control form-control-sm" id="mod_link_{{ $i }}" name="consensi[{{ $i }}][link_url]" value="{{ old('consensi.'.$i.'.link_url', $consenso['link_url']) }}" placeholder="Link facoltativo (https://…)">
                    </div>
                    <div class="col-md-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="consensi[{{ $i }}][obbligatorio]" value="1" id="mod_obbl_{{ $i }}" @checked(old('consensi.'.$i.'.obbligatorio', $consenso['obbligatorio']))>
                            <label class="form-check-label small" for="mod_obbl_{{ $i }}">Obbligatorio</label>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="form-text">Salvando compaiono altre righe vuote per nuovi consensi.</div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva moduli pubblici</button>
</form>
