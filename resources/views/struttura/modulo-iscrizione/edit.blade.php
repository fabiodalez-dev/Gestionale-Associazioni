@extends('layouts.app')

@section('title', 'Modulo di iscrizione — '.$associazione->nome)

@section('content')
@php
    $aperte = (bool) old('iscrizioni_socio_aperte', $associazione->iscrizioni()->iscrizioni_socio_aperte);
    $iconeStandard = ['telefono' => 'bi-telephone', 'codice_fiscale' => 'bi-person-vcard', 'data_nascita' => 'bi-calendar3', 'indirizzo' => 'bi-geo-alt', 'citta' => 'bi-building', 'provincia' => 'bi-map', 'cap' => 'bi-mailbox'];
    $tipiStandard = ['telefono' => 'Telefono', 'codice_fiscale' => 'Testo · controllato', 'data_nascita' => 'Data · tutore se minorenne', 'indirizzo' => 'Testo', 'citta' => 'Testo', 'provincia' => 'Sigla', 'cap' => 'Numero'];
    $iconeTipo = ['text' => 'bi-fonts', 'email' => 'bi-envelope', 'tel' => 'bi-telephone', 'number' => 'bi-hash', 'date' => 'bi-calendar3', 'url' => 'bi-link-45deg', 'textarea' => 'bi-text-paragraph', 'select' => 'bi-list-ul', 'checkbox' => 'bi-check2-square'];
    $nomiTipo = ['text' => 'Testo breve', 'email' => 'Email', 'tel' => 'Telefono', 'number' => 'Numero', 'date' => 'Data', 'url' => 'Link', 'textarea' => 'Testo lungo', 'select' => 'Scelta', 'checkbox' => 'Casella di consenso'];
    $palette = ['text', 'email', 'date', 'select', 'checkbox', 'number', 'tel', 'textarea'];
    // Campi personalizzati: prima quelli inclusi nel loro ordine, poi gli altri
    $selezionati = old('campi_personalizzati', $idSelezionati);
    $personalizzatiOrdinati = $campiPersonalizzati->sortBy(fn ($c) => ($i = array_search($c->id, $selezionati, true)) === false ? 1000 : $i)->values();
    $conversione = $statistiche['invii'] > 0 ? round($statistiche['approvate'] * 100 / $statistiche['invii']) : null;
    $codiceEmbed = '<iframe src="'.$urlAnteprima.'" style="width:100%;min-height:900px;border:0" title="Iscrizione a '.e($associazione->nome).'"></iframe>';
@endphp

<form method="POST" action="{{ route('configurazioni.modulo-iscrizione.salva') }}" id="builderForm">
    @csrf

    <div class="page-head">
        <div>
            <div class="crumb"><a href="{{ route('configurazioni.index') }}">Configurazioni</a> / Moduli pubblici</div>
            <h1 class="d-flex align-items-center gap-2 flex-wrap">
                Modulo di iscrizione
                <span class="badge {{ $aperte ? 'bg-success' : 'bg-secondary' }}" id="badgeStato">{{ $aperte ? 'Pubblicato' : 'Chiuso' }}</span>
            </h1>
        </div>
        <div class="actions">
            <div class="link-box">
                <i class="bi bi-link-45deg"></i>
                <span class="mono text-truncate" id="linkModulo">{{ preg_replace('#^https?://#', '', $urlAnteprima) }}</span>
                <button type="button" class="btn btn-sm btn-soft" data-copia="{{ $urlAnteprima }}">Copia</button>
            </div>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modaleEmbed"><i class="bi bi-code-slash"></i>Codice embed</button>
            <a class="btn btn-outline-secondary" href="{{ $urlAnteprima }}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Anteprima form pubblico</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i>Salva modulo di iscrizione</button>
        </div>
    </div>

    <div class="builder">
        {{-- Colonna sinistra: aggiungi campo, impostazioni, altri moduli --}}
        <aside class="builder-side">
            <div class="card">
                <div class="card-body">
                    <div class="section-label">Aggiungi campo</div>
                    <div class="palette">
                        @foreach ($palette as $tipo)
                            <button type="button" class="palette-item" data-nuovo-campo="{{ $tipo }}">
                                <i class="bi {{ $iconeTipo[$tipo] }}"></i><span>{{ $nomiTipo[$tipo] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="section-label">Pubblicazione</div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="iscrizioni_socio_aperte" name="iscrizioni_socio_aperte" value="1" @checked($aperte)>
                        <label class="form-check-label" for="iscrizioni_socio_aperte">Iscrizioni socio aperte dal sito pubblico</label>
                    </div>
                    <label class="form-label" for="modalita_iscrizione">Modalità di iscrizione</label>
                    <select class="form-select" id="modalita_iscrizione" name="modalita_iscrizione">
                        <option value="{{ \App\Models\Associazione::MODALITA_PREISCRIZIONE }}" @selected(old('modalita_iscrizione', $associazione->iscrizioni()->modalita_iscrizione) === \App\Models\Associazione::MODALITA_PREISCRIZIONE)>Con approvazione (socio "In attesa")</option>
                        <option value="{{ \App\Models\Associazione::MODALITA_DIRETTA }}" @selected(old('modalita_iscrizione', $associazione->iscrizioni()->modalita_iscrizione) === \App\Models\Associazione::MODALITA_DIRETTA)>Diretta (socio "Attivo" subito)</option>
                    </select>
                    <label class="form-label mt-3" for="intro_html">Testo introduttivo</label>
                    <textarea class="form-control" id="intro_html" name="intro_html" rows="4" placeholder="<p>Benvenuto! Compila il modulo per richiedere l'iscrizione…</p>">{{ old('intro_html', $introHtml) }}</textarea>
                    <div class="form-text">Paragrafi, grassetti, elenchi e link; il resto viene rimosso.</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="section-label">Altri moduli</div>
                    <a class="other-form is-current" href="{{ route('configurazioni.modulo-iscrizione') }}">
                        <b>Iscrizione online</b><small>{{ $statistiche['invii'] }} invii negli ultimi 30 giorni · {{ $aperte ? 'attivo' : 'chiuso' }}</small>
                    </a>
                    <a class="other-form" href="{{ route('configurazioni.index', ['section' => 'moduli']) }}">
                        <b>Pre-iscrizione e aspetto</b><small>Colori, logo, testi e consensi</small>
                    </a>
                    @if (\Illuminate\Support\Facades\Route::has('donazioni.index'))
                        <a class="other-form" href="{{ route('donazioni.index') }}"><b>Donazioni</b><small>Pagina pubblica per le donazioni</small></a>
                    @endif
                    @if (\Illuminate\Support\Facades\Route::has('turni.index'))
                        <a class="other-form" href="{{ route('turni.index') }}"><b>Candidature volontari</b><small>Turni aperti alle candidature</small></a>
                    @endif
                </div>
            </div>
        </aside>

        {{-- Colonna centrale: struttura del modulo --}}
        <section class="card builder-main">
            <div class="card-head">
                <h2>Struttura del modulo</h2>
                <span class="small text-muted" data-conteggio-campi></span>
            </div>
            <div class="px-4 pb-4">
                <div class="section-label mt-2">Dati dell'account</div>
                <p class="small text-muted mt-n2">Nome, cognome ed email arrivano dall'account registrato: sono sempre presenti.</p>
                <ul class="field-list is-locked">
                    @foreach (['Nome', 'Cognome', 'Email'] as $fisso)
                        <li class="field-row" data-anteprima-campo="{{ $fisso }}" data-obbligatorio="1" data-tipo="{{ $fisso === 'Email' ? 'email' : 'text' }}" data-visibile="1">
                            <span class="field-grip"><i class="bi bi-lock"></i></span>
                            <span class="field-icon"><i class="bi {{ $fisso === 'Email' ? 'bi-envelope' : 'bi-fonts' }}"></i></span>
                            <span class="field-name"><b>{{ $fisso }} <span class="req">*</span></b><small>Dall'account</small></span>
                        </li>
                    @endforeach
                </ul>

                <div class="section-label mt-4">Dati personali</div>
                <ul class="field-list" id="listaStandard" aria-label="Campi standard, trascina per riordinare">
                    @foreach ($campiStandard as $i => $riga)
                        @php
                            $campo = $riga['campo'];
                            $visibile = (bool) old('campi_standard.'.$campo.'.visibile', $riga['visibile']);
                            $obbligatorio = (bool) old('campi_standard.'.$campo.'.obbligatorio', $riga['obbligatorio']);
                        @endphp
                        <li class="field-row {{ $visibile ? '' : 'is-off' }}" data-anteprima-campo="{{ $labels[$campo] ?? $campo }}" data-tipo="{{ $campo === 'data_nascita' ? 'date' : 'text' }}">
                            <span class="field-grip" title="Trascina per riordinare"><i class="bi bi-grip-vertical"></i></span>
                            <span class="field-icon"><i class="bi {{ $iconeStandard[$campo] ?? 'bi-fonts' }}"></i></span>
                            <span class="field-name"><b>{{ $labels[$campo] ?? $campo }} <span class="req" @unless ($obbligatorio && $visibile) hidden @endunless>*</span></b><small>{{ $tipiStandard[$campo] ?? 'Testo' }}</small></span>
                            <label class="field-toggle"><input class="form-check-input" type="checkbox" id="std_{{ $campo }}_visibile" name="campi_standard[{{ $campo }}][visibile]" value="1" data-ruolo="visibile" @checked($visibile)> Visibile</label>
                            <label class="field-toggle"><input class="form-check-input" type="checkbox" id="std_{{ $campo }}_obbligatorio" name="campi_standard[{{ $campo }}][obbligatorio]" value="1" data-ruolo="obbligatorio" @checked($obbligatorio)> Obbligatorio</label>
                            <input type="hidden" id="std_{{ $campo }}_ordine" name="campi_standard[{{ $campo }}][ordine]" value="{{ old('campi_standard.'.$campo.'.ordine', $i + 1) }}" data-ordine>
                        </li>
                    @endforeach
                </ul>

                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div class="section-label m-0">Campi dell'associazione</div>
                    @if (\Illuminate\Support\Facades\Route::has('config-campi.index'))
                        <a class="small" href="{{ route('config-campi.index') }}">Gestisci campi personalizzati</a>
                    @endif
                </div>
                @if ($personalizzatiOrdinati->isEmpty())
                    <div class="empty-hint mt-2"><i class="bi bi-plus-square-dotted"></i>Nessun campo personalizzato: aggiungine uno dalla colonna "Aggiungi campo".</div>
                @else
                    <ul class="field-list mt-2" id="listaPersonalizzati" aria-label="Campi personalizzati, trascina per riordinare">
                        @foreach ($personalizzatiOrdinati as $campo)
                            @php $incluso = in_array($campo->id, $selezionati, true); @endphp
                            <li class="field-row {{ $incluso ? '' : 'is-off' }}" data-anteprima-campo="{{ $campo->nome_campo }}" data-obbligatorio="{{ $campo->obbligatorio ? '1' : '0' }}" data-tipo="{{ $campo->tipo_campo }}" data-opzioni="{{ $campo->opzioni }}">
                                <span class="field-grip" title="Trascina per riordinare"><i class="bi bi-grip-vertical"></i></span>
                                <span class="field-icon"><i class="bi {{ $iconeTipo[$campo->tipo_campo] ?? 'bi-fonts' }}"></i></span>
                                <span class="field-name"><b>{{ $campo->nome_campo }} @if ($campo->obbligatorio)<span class="req">*</span>@endif</b><small>{{ $nomiTipo[$campo->tipo_campo] ?? $campo->tipo_campo }}{{ $campo->descrizione ? ' · '.\Illuminate\Support\Str::limit($campo->descrizione, 40) : '' }}</small></span>
                                <label class="field-toggle"><input class="form-check-input" type="checkbox" id="cp_{{ $campo->id }}" name="campi_personalizzati[]" value="{{ $campo->id }}" data-ruolo="visibile" @checked($incluso)> Nel modulo</label>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- Colonna destra: anteprima --}}
        <aside class="builder-preview">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold text-body"><i class="bi bi-eye me-1"></i>Anteprima pubblica</span>
                <div class="segmented" role="group" aria-label="Dispositivo">
                    <button type="button" class="active" data-dispositivo="desktop">Desktop</button>
                    <button type="button" data-dispositivo="mobile">Mobile</button>
                </div>
            </div>
            <div class="preview-frame" id="previewFrame">
                <div class="preview-bar"><span></span><span></span><span></span><span class="mono">{{ preg_replace('#^https?://#', '', $urlAnteprima) }}</span></div>
                <div class="preview-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="avatar avatar-square">{{ mb_strtoupper(mb_substr($associazione->nome, 0, 2)) }}</span>
                        <div><div class="small text-muted">{{ $associazione->nome }}</div><div class="fw-bold text-body fs-6 lh-sm">Diventa socio</div></div>
                    </div>
                    <div class="preview-intro small text-muted mb-3" id="previewIntro"></div>
                    <div class="preview-fields" id="previewFields"></div>
                    <div class="form-check small mt-2"><input class="form-check-input" type="checkbox" disabled><label class="form-check-label">Ho letto l'informativa privacy *</label></div>
                    <button type="button" class="btn btn-primary w-100 mt-3" disabled>Invia la richiesta</button>
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-4"><div class="mini-stat"><div class="l">Invii 30 gg</div><div class="v">{{ $statistiche['invii'] }}</div></div></div>
                <div class="col-4"><div class="mini-stat"><div class="l">Da approvare</div><div class="v">{{ $statistiche['in_attesa'] }}</div></div></div>
                <div class="col-4"><div class="mini-stat"><div class="l">Approvate</div><div class="v">{{ $conversione === null ? '—' : $conversione.'%' }}</div></div></div>
            </div>
        </aside>
    </div>
</form>

{{-- Nuovo campo personalizzato, aggiunto subito al modulo --}}
<div class="modal fade" id="modaleCampo" tabindex="-1" aria-hidden="true" aria-labelledby="modaleCampoTitolo">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('config-campi.store') }}" class="modal-content">
            @csrf
            <input type="hidden" name="aggiungi_al_modulo" value="1">
            <input type="hidden" name="tipo_campo" id="nuovoTipo" value="text">
            <div class="modal-header">
                <h5 class="modal-title" id="modaleCampoTitolo">Nuovo campo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="nuovoNome">Etichetta *</label>
                <input type="text" class="form-control mb-3" id="nuovoNome" name="nome_campo" maxlength="100" required placeholder="Es. Taglia maglietta">
                <div id="nuoveOpzioni" hidden>
                    <label class="form-label" for="nuovoOpzioniTesto">Opzioni (una per riga) *</label>
                    <textarea class="form-control mb-3" id="nuovoOpzioniTesto" name="opzioni" rows="3" placeholder="S&#10;M&#10;L"></textarea>
                </div>
                <label class="form-label" for="nuovaDescrizione">Aiuto sotto il campo</label>
                <input type="text" class="form-control mb-3" id="nuovaDescrizione" name="descrizione" maxlength="255">
                <div class="form-check form-switch">
                    <input type="hidden" name="obbligatorio" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="nuovoObbligatorio" name="obbligatorio" value="1">
                    <label class="form-check-label" for="nuovoObbligatorio">Obbligatorio</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">Aggiungi al modulo</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modaleEmbed" tabindex="-1" aria-hidden="true" aria-labelledby="modaleEmbedTitolo">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modaleEmbedTitolo">Codice embed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Incolla questo codice nel sito dell'associazione per mostrare il modulo, oppure condividi direttamente il link.</p>
                <textarea class="form-control mono" rows="4" readonly id="codiceEmbed">{{ $codiceEmbed }}</textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-copia-da="codiceEmbed"><i class="bi bi-clipboard"></i>Copia codice</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/vendor/sortablejs/Sortable.min.js') }}"></script>
    <script src="{{ asset('assets/js/costruttore-modulo.js') }}"></script>
@endpush
