@extends('layouts.app')

@section('title', 'Modulo di iscrizione — '.$associazione->nome)

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-ui-checks-grid me-2"></i>Modulo di iscrizione</h1>
    <div>
        <a class="btn btn-sm btn-outline-secondary me-2" href="{{ route('configurazioni.index') }}">
            <i class="bi bi-arrow-left me-1"></i>Configurazioni
        </a>
        <a class="btn btn-sm btn-outline-primary" href="{{ $urlAnteprima }}" target="_blank" rel="noopener">
            <i class="bi bi-eye me-1"></i>Anteprima form pubblico
        </a>
    </div>
</div>

{{-- Flash di successo ed errori di validazione: resi da partials.flash nel layout --}}

<form method="POST" action="{{ route('configurazioni.modulo-iscrizione.salva') }}">
    @csrf

    {{-- ============ APERTURA E MODALITÀ ============ --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-toggles me-2"></i>Apertura iscrizioni</div>
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="iscrizioni_socio_aperte"
                               name="iscrizioni_socio_aperte" value="1"
                               @checked(old('iscrizioni_socio_aperte', $associazione->iscrizioni()->iscrizioni_socio_aperte))>
                        <label class="form-check-label" for="iscrizioni_socio_aperte">
                            Iscrizioni socio aperte dal sito pubblico
                        </label>
                    </div>
                    <div class="form-text">Se disattivato, il form pubblico mostra la pagina "iscrizioni chiuse".</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="modalita_iscrizione">Modalità di iscrizione</label>
                    <select class="form-select" id="modalita_iscrizione" name="modalita_iscrizione">
                        <option value="{{ \App\Models\Associazione::MODALITA_PREISCRIZIONE }}"
                                @selected(old('modalita_iscrizione', $associazione->iscrizioni()->modalita_iscrizione) === \App\Models\Associazione::MODALITA_PREISCRIZIONE)>
                            Preiscrizione con approvazione (socio "In Attesa")
                        </option>
                        <option value="{{ \App\Models\Associazione::MODALITA_DIRETTA }}"
                                @selected(old('modalita_iscrizione', $associazione->iscrizioni()->modalita_iscrizione) === \App\Models\Associazione::MODALITA_DIRETTA)>
                            Diretta (socio "Attivo" subito, senza approvazione)
                        </option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ TESTO INTRODUTTIVO ============ --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-card-text me-2"></i>Testo introduttivo</div>
        <div class="card-body">
            <textarea class="form-control" id="intro_html" name="intro_html" rows="6"
                      placeholder="<p>Benvenuto! Compila il modulo per richiedere l'iscrizione…</p>">{{ old('intro_html', $introHtml) }}</textarea>
            <div class="form-text">
                Mostrato in cima al form pubblico. L'HTML viene sanitizzato al salvataggio
                (consentiti: paragrafi, titoli, grassetti, liste, link).
            </div>
        </div>
    </div>

    {{-- ============ CAMPI STANDARD ============ --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-list-check me-2"></i>Campi standard</div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Nome, cognome ed email arrivano dall'account registrato e non compaiono nel form.
                L'ordine determina la posizione del campo nel form pubblico.
            </p>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Campo</th>
                            <th class="text-center">Visibile</th>
                            <th class="text-center">Obbligatorio</th>
                            <th class="text-center" style="width: 110px;">Ordine</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campiStandard as $i => $riga)
                            @php $campo = $riga['campo']; @endphp
                            <tr>
                                <td>{{ $labels[$campo] ?? $campo }}</td>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox"
                                           id="std_{{ $campo }}_visibile"
                                           name="campi_standard[{{ $campo }}][visibile]" value="1"
                                           aria-label="{{ $labels[$campo] ?? $campo }} visibile"
                                           @checked(old('campi_standard.'.$campo.'.visibile', $riga['visibile']))>
                                </td>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox"
                                           id="std_{{ $campo }}_obbligatorio"
                                           name="campi_standard[{{ $campo }}][obbligatorio]" value="1"
                                           aria-label="{{ $labels[$campo] ?? $campo }} obbligatorio"
                                           @checked(old('campi_standard.'.$campo.'.obbligatorio', $riga['obbligatorio']))>
                                </td>
                                <td class="text-center">
                                    <input class="form-control form-control-sm text-center" type="number"
                                           id="std_{{ $campo }}_ordine"
                                           name="campi_standard[{{ $campo }}][ordine]"
                                           min="1" max="99" required
                                           aria-label="Ordine di {{ $labels[$campo] ?? $campo }}"
                                           value="{{ old('campi_standard.'.$campo.'.ordine', $i + 1) }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============ CAMPI PERSONALIZZATI ============ --}}
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-input-cursor-text me-2"></i>Campi personalizzati</span>
            @php $navCampi = \Illuminate\Support\Facades\Route::has('config-campi.index') ? route('config-campi.index') : '#'; @endphp
            <a class="btn btn-sm btn-outline-secondary" href="{{ $navCampi }}">
                <i class="bi bi-gear me-1"></i>Gestisci campi personalizzati
            </a>
        </div>
        <div class="card-body">
            @if ($campiPersonalizzati->isEmpty())
                <p class="text-muted mb-0">
                    Nessun campo personalizzato configurato.
                    <a href="{{ $navCampi }}">Creane uno</a> per poterlo includere nel modulo.
                </p>
            @else
                <p class="text-muted small mb-2">Seleziona i campi personalizzati da includere nel form pubblico.</p>
                <div class="row g-2">
                    @foreach ($campiPersonalizzati as $campo)
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       id="cp_{{ $campo->id }}"
                                       name="campi_personalizzati[]" value="{{ $campo->id }}"
                                       @checked(in_array($campo->id, old('campi_personalizzati', $idSelezionati), true))>
                                <label class="form-check-label" for="cp_{{ $campo->id }}">
                                    {{ $campo->nome_campo }}
                                    <small class="text-muted">({{ $campo->tipo_campo }}@if ($campo->obbligatorio), obbligatorio @endif)</small>
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Salva modulo di iscrizione</button>
</form>
@endsection
