@extends('layouts.app')

@section('title', 'Configurazioni — '.$associazione->nome)

@php
    $sezioni = [
        'info' => ['bi-card-text', 'Dati anagrafici'],
        'logo' => ['bi-image', 'Logo'],
        'sedi' => ['bi-geo-alt', 'Sedi'],
        'tesseramento' => ['bi-credit-card-2-front', 'Tesseramento'],
        'template-email' => ['bi-envelope', 'Template email scadenza'],
        'privacy' => ['bi-shield-check', 'Privacy Policy'],
        'campi-obbligatori' => ['bi-ui-checks', 'Campi obbligatori'],
        'tessera-template' => ['bi-postcard', 'Template tessera'],
        'pagamenti' => ['bi-credit-card', 'Pagamenti online'],
        'invito' => ['bi-qr-code', 'Invita al mini-sito'],
    ];
    if (! array_key_exists($section, $sezioni)) {
        $section = 'info';
    }
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-gear me-2"></i>Configurazioni</h1>
    <span class="text-muted">{{ $associazione->nome }}</span>
</div>

<div class="row">
    <div class="col-lg-3 mb-3">
        <div class="list-group">
            @foreach ($sezioni as $chiave => [$icona, $label])
                <a class="list-group-item list-group-item-action {{ $section === $chiave ? 'active' : '' }}"
                   href="{{ route('configurazioni.index', ['section' => $chiave]) }}">
                    <i class="bi {{ $icona }} me-2"></i>{{ $label }}
                </a>
            @endforeach
        </div>
        <div class="card mt-3">
            <div class="card-header">Altre configurazioni</div>
            <div class="list-group list-group-flush">
                @php $nav = fn (string $name, array $params = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $params) : '#'; @endphp
                <a class="list-group-item list-group-item-action" href="{{ $nav('configurazioni.modulo-iscrizione') }}">Modulo di iscrizione</a>
                <a class="list-group-item list-group-item-action" href="{{ $nav('tipi-socio.index') }}">Tipi Socio</a>
                <a class="list-group-item list-group-item-action" href="{{ $nav('categorie-socio.index') }}">Categorie Socio</a>
                <a class="list-group-item list-group-item-action" href="{{ $nav('amministratori.index') }}">Amministratori</a>
            </div>
        </div>
    </div>

    <div class="col-lg-9">
        {{-- ============ DATI ANAGRAFICI ============ --}}
        @if ($section === 'info')
            <div class="card">
                <div class="card-header"><i class="bi bi-card-text me-2"></i>Dati anagrafici</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('configurazioni.info') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="cfg_nome">Nome *</label>
                                <input type="text" class="form-control" id="cfg_nome" name="nome" required value="{{ old('nome', $associazione->nome) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="cfg_email">Email</label>
                                <input type="email" class="form-control" id="cfg_email" name="email" value="{{ old('email', $associazione->email) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cfg_piva">Partita IVA</label>
                                <input type="text" class="form-control" id="cfg_piva" name="partita_iva" value="{{ old('partita_iva', $associazione->partita_iva) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cfg_cf">Codice fiscale</label>
                                <input type="text" class="form-control" id="cfg_cf" name="codice_fiscale" value="{{ old('codice_fiscale', $associazione->codice_fiscale) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cfg_tel">Telefono</label>
                                <input type="text" class="form-control" id="cfg_tel" name="telefono" value="{{ old('telefono', $associazione->telefono) }}">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="cfg_ind">Indirizzo</label>
                                <input type="text" class="form-control" id="cfg_ind" name="indirizzo" value="{{ old('indirizzo', $associazione->indirizzo) }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="cfg_citta">Città</label>
                                <input type="text" class="form-control" id="cfg_citta" name="citta" value="{{ old('citta', $associazione->citta) }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="cfg_prov">Provincia</label>
                                <input type="text" class="form-control" id="cfg_prov" name="provincia" maxlength="2" value="{{ old('provincia', $associazione->provincia) }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="cfg_cap">CAP</label>
                                <input type="text" class="form-control" id="cfg_cap" name="cap" maxlength="5" value="{{ old('cap', $associazione->cap) }}">
                            </div>
                        </div>
                        <button class="btn btn-primary mt-3" type="submit">Salva dati anagrafici</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ LOGO ============ --}}
        @if ($section === 'logo')
            <div class="card">
                <div class="card-header"><i class="bi bi-image me-2"></i>Logo associazione</div>
                <div class="card-body">
                    @if ($associazione->logo_url)
                        <p><img src="{{ asset('storage/'.$associazione->logo_url) }}" alt="Logo {{ $associazione->nome }}" style="max-height: 120px;" class="rounded border p-2"></p>
                    @else
                        <p class="text-muted">Nessun logo caricato.</p>
                    @endif
                    <form method="POST" action="{{ route('configurazioni.logo') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="cfg_logo">Nuovo logo (JPG/PNG, max 2 MB)</label>
                            <input type="file" class="form-control" id="cfg_logo" name="logo_file" accept=".jpg,.jpeg,.png" required>
                        </div>
                        <button class="btn btn-primary" type="submit">Carica logo</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ SEDI ============ --}}
        @if ($section === 'sedi')
            <div class="card" id="sedi">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-geo-alt me-2"></i>Sedi</span>
                    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalSede" id="btnNuovaSede">
                        <i class="bi bi-plus-lg me-1"></i>Nuova sede
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Nome</th><th>Indirizzo</th><th>Città</th><th>Prov.</th><th>CAP</th><th>Responsabile</th><th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sedi as $sede)
                                <tr>
                                    <td><strong>{{ $sede->nome }}</strong></td>
                                    <td>{{ $sede->indirizzo }}</td>
                                    <td>{{ $sede->citta }}</td>
                                    <td>{{ $sede->provincia }}</td>
                                    <td>{{ $sede->cap }}</td>
                                    <td>{{ $sede->responsabile }}</td>
                                    <td class="text-end">
                                        @php $sedeJson = json_encode($sede->only(['id', 'nome', 'indirizzo', 'citta', 'provincia', 'cap', 'responsabile'])); @endphp
                                        <button class="btn btn-sm btn-outline-primary btn-edit-sede" type="button"
                                                data-bs-toggle="modal" data-bs-target="#modalSede"
                                                data-sede="{{ $sedeJson }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="{{ route('sedi.destroy', $sede->id) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare questa sede?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">Nessuna sede presente.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal fade" id="modalSede" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('sedi.save') }}">
                            @csrf
                            <input type="hidden" name="id" id="sede_id" value="{{ old('id') }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalSedeTitolo">Nuova sede</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label" for="sede_nome">Nome *</label>
                                        <input type="text" class="form-control" id="sede_nome" name="nome" required value="{{ old('nome') }}">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="sede_indirizzo">Indirizzo</label>
                                        <input type="text" class="form-control" id="sede_indirizzo" name="indirizzo" value="{{ old('indirizzo') }}">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="sede_citta">Città</label>
                                        <input type="text" class="form-control" id="sede_citta" name="citta" value="{{ old('citta') }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="sede_provincia">Provincia</label>
                                        <input type="text" class="form-control" id="sede_provincia" name="provincia" maxlength="2" value="{{ old('provincia') }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="sede_cap">CAP</label>
                                        <input type="text" class="form-control" id="sede_cap" name="cap" maxlength="5" value="{{ old('cap') }}">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="sede_responsabile">Responsabile</label>
                                        <input type="text" class="form-control" id="sede_responsabile" name="responsabile" value="{{ old('responsabile') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                                <button type="submit" class="btn btn-primary">Salva sede</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ TESSERAMENTO ============ --}}
        @if ($section === 'tesseramento')
            <div class="card">
                <div class="card-header"><i class="bi bi-credit-card-2-front me-2"></i>Tesseramento</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('configurazioni.tesseramento') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="cfg_scadenza">Tipo scadenza default</label>
                                <select class="form-select" id="cfg_scadenza" name="tipo_scadenza_default">
                                    <option value="solare" @selected(old('tipo_scadenza_default', $associazione->tipo_scadenza_default) === 'solare')>Anno Solare (31 Dicembre)</option>
                                    <option value="annuale" @selected(old('tipo_scadenza_default', $associazione->tipo_scadenza_default) === 'annuale')>Annuale (365 giorni da iscrizione)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="cfg_giorni">Giorni di preavviso notifiche scadenza</label>
                                <input type="number" class="form-control" id="cfg_giorni" name="giorni_notifica_scadenza" min="1" max="365"
                                       value="{{ old('giorni_notifica_scadenza', $associazione->giorni_notifica_scadenza) }}">
                            </div>
                        </div>
                        <button class="btn btn-primary mt-3" type="submit">Salva tesseramento</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ TEMPLATE EMAIL SCADENZA ============ --}}
        @if ($section === 'template-email')
            <div class="card">
                <div class="card-header"><i class="bi bi-envelope me-2"></i>Template email scadenza</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('configurazioni.template-email') }}">
                        @csrf
                        <div class="mb-2">
                            <textarea class="form-control" name="template_email_scadenza" rows="6"
                                      placeholder="Ciao {NOME_SOCIO}, ti ricordiamo che la tua quota scade il {DATA_SCADENZA}.">{{ old('template_email_scadenza', $associazione->template_email_scadenza) }}</textarea>
                        </div>
                        <p class="text-muted small">
                            Placeholder disponibili: <code>{NOME_SOCIO}</code>, <code>{COGNOME_SOCIO}</code>, <code>{DATA_SCADENZA}</code>, <code>{IMPORTO_QUOTA}</code>
                        </p>
                        <button class="btn btn-primary" type="submit">Salva template</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ PRIVACY POLICY ============ --}}
        @if ($section === 'privacy')
            <div class="card">
                <div class="card-header"><i class="bi bi-shield-check me-2"></i>Privacy Policy</div>
                <div class="card-body">
                    <p class="text-muted small">
                        Il testo viene mostrato sulla pagina pubblica
                        <a href="{{ route('privacy-policy.show', ['associazione' => $associazione->id]) }}" target="_blank" rel="noopener">privacy policy</a>
                        e linkato dal form di pre-iscrizione. L'HTML viene sanitizzato al salvataggio
                        (consentiti: titoli, grassetti, liste, link, tabelle).
                    </p>
                    <form method="POST" action="{{ route('configurazioni.privacy-policy') }}">
                        @csrf
                        <textarea class="form-control" name="privacy_policy_html" id="privacy_policy_html" rows="14">{{ old('privacy_policy_html', $associazione->privacy_policy_html) }}</textarea>
                        <button class="btn btn-primary mt-3" type="submit">Salva privacy policy</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ CAMPI OBBLIGATORI ============ --}}
        @if ($section === 'campi-obbligatori')
            <div class="card">
                <div class="card-header"><i class="bi bi-ui-checks me-2"></i>Campi obbligatori</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('configurazioni.campi-obbligatori') }}">
                        @csrf
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Campo</th>
                                        <th class="text-center">Backend</th>
                                        <th class="text-center">Pre-iscrizione</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['nome' => 'Nome', 'cognome' => 'Cognome', 'email' => 'Email'] as $campo => $label)
                                        <tr class="table-light">
                                            <td>{{ $label }}</td>
                                            <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                            <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                        </tr>
                                    @endforeach
                                    @foreach ($backendFields as $campo)
                                        <tr>
                                            <td>{{ $labels[$campo] ?? $campo }}</td>
                                            <td class="text-center">
                                                <input class="form-check-input" type="checkbox" name="required_backend[{{ $campo }}]" value="1"
                                                       @checked($requiredConfig['backend'][$campo] ?? false)>
                                            </td>
                                            <td class="text-center">
                                                @if (! in_array($campo, $preiscrizioneFields, true))
                                                    <span class="badge bg-light text-muted border">N/A</span>
                                                @elseif ($campo === 'privacy_consenso')
                                                    <span class="badge bg-success">GDPR</span>
                                                    <input type="hidden" name="required_preiscrizione[privacy_consenso]" value="1">
                                                @else
                                                    <input class="form-check-input" type="checkbox" name="required_preiscrizione[{{ $campo }}]" value="1"
                                                           @checked($requiredConfig['preiscrizione'][$campo] ?? false)>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    @if ($campiPersonalizzati->isNotEmpty())
                                        <tr class="table-light"><td colspan="3"><strong>Campi personalizzati</strong></td></tr>
                                        @foreach ($campiPersonalizzati as $campo)
                                            <tr>
                                                <td>{{ $campo->nome_campo }} <small class="text-muted">({{ $campo->tipo_campo }})</small></td>
                                                <td class="text-center">
                                                    <input class="form-check-input" type="checkbox" name="required_custom_backend[]" value="{{ $campo->id }}"
                                                           @checked($campo->obbligatorio)>
                                                </td>
                                                <td class="text-center">
                                                    <input class="form-check-input" type="checkbox" name="required_custom_preiscrizione[]" value="{{ $campo->id }}"
                                                           @checked($campo->obbligatorio_preiscrizione)>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endif
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary" type="submit">Salva configurazione</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ TEMPLATE TESSERA ============ --}}
        @if ($section === 'tessera-template')
            <div class="card">
                <div class="card-header"><i class="bi bi-postcard me-2"></i>Template tessera per tipo socio</div>
                <div class="card-body">
                    <form method="GET" action="{{ route('configurazioni.index') }}" class="mb-3">
                        <input type="hidden" name="section" value="tessera-template">
                        <label class="form-label" for="tipo_id_sel">Tipo socio</label>
                        <select class="form-select" id="tipo_id_sel" name="tipo_id" onchange="this.form.submit()">
                            <option value="">Template default (tutti i tipi)</option>
                            @foreach ($tipiSocio as $tipo)
                                <option value="{{ $tipo->id }}" @selected($tipoSelezionato === $tipo->id)>{{ $tipo->nome }}</option>
                            @endforeach
                        </select>
                    </form>
                    <form method="POST" action="{{ route('configurazioni.tessera-template') }}">
                        @csrf
                        <input type="hidden" name="tipo_id" value="{{ $tipoSelezionato }}">
                        <textarea class="form-control" name="contenuto" rows="6" required
                                  placeholder="Il/La sottoscritto/a {NOME_COMPLETO}, tessera n. {NUMERO_TESSERA}, è iscritto/a all'associazione {ASSOCIAZIONE_NOME} per l'anno {ANNO_VALIDITA}.">{{ old('contenuto', $tesseraTemplate?->contenuto) }}</textarea>
                        <p class="text-muted small mt-2">
                            Placeholder: <code>{ASSOCIAZIONE_NOME}</code> <code>{ASSOCIAZIONE_CODICE_FISCALE}</code> <code>{ASSOCIAZIONE_INDIRIZZO}</code>
                            <code>{NOME}</code> <code>{COGNOME}</code> <code>{NOME_COMPLETO}</code> <code>{NUMERO_SOCIO}</code> <code>{TIPO_SOCIO}</code>
                            <code>{CATEGORIA_SOCIO}</code> <code>{NUMERO_TESSERA}</code> <code>{ANNO_VALIDITA}</code> <code>{DATA_EMISSIONE}</code> <code>{DATA_SCADENZA}</code>
                        </p>
                        <button class="btn btn-primary" type="submit">Salva template</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ PAGAMENTI ONLINE ============ --}}
        @if ($section === 'pagamenti')
            <div class="card">
                <div class="card-header"><i class="bi bi-credit-card me-2"></i>Pagamenti online (Stripe / PayPal)</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('configurazioni.pagamenti') }}">
                        @csrf
                        <h6 class="text-uppercase text-muted small">Stripe</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="stripe_enabled" name="stripe_enabled" value="1"
                                           @checked(old('stripe_enabled', $paymentSettings?->stripe_enabled))>
                                    <label class="form-check-label" for="stripe_enabled">Abilita Stripe</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="stripe_pk">Publishable key</label>
                                <input type="text" class="form-control" id="stripe_pk" name="stripe_publishable_key"
                                       value="{{ old('stripe_publishable_key', $paymentSettings?->stripe_publishable_key) }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="stripe_sk">Secret key</label>
                                <input type="password" class="form-control" id="stripe_sk" name="stripe_secret_key" autocomplete="off"
                                       placeholder="{{ $paymentSettings?->stripe_secret_key_encrypted ? '••••••••' : '' }}">
                                <div class="form-text">Vuoto = invariata.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="stripe_whs">Webhook secret</label>
                                <input type="password" class="form-control" id="stripe_whs" name="stripe_webhook_secret" autocomplete="off"
                                       placeholder="{{ $paymentSettings?->stripe_webhook_secret_encrypted ? '••••••••' : '' }}">
                                <div class="form-text">Vuoto = invariato.</div>
                            </div>
                        </div>

                        <h6 class="text-uppercase text-muted small">PayPal</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="paypal_enabled" name="paypal_enabled" value="1"
                                           @checked(old('paypal_enabled', $paymentSettings?->paypal_enabled))>
                                    <label class="form-check-label" for="paypal_enabled">Abilita PayPal</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="paypal_cid">Client ID</label>
                                <input type="text" class="form-control" id="paypal_cid" name="paypal_client_id"
                                       value="{{ old('paypal_client_id', $paymentSettings?->paypal_client_id) }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="paypal_cs">Client secret</label>
                                <input type="password" class="form-control" id="paypal_cs" name="paypal_client_secret" autocomplete="off"
                                       placeholder="{{ $paymentSettings?->paypal_client_secret_encrypted ? '••••••••' : '' }}">
                                <div class="form-text">Vuoto = invariato.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="paypal_mode">Modalità</label>
                                <select class="form-select" id="paypal_mode" name="paypal_mode">
                                    <option value="sandbox" @selected(old('paypal_mode', $paymentSettings?->paypal_mode ?? 'sandbox') === 'sandbox')>Sandbox</option>
                                    <option value="live" @selected(old('paypal_mode', $paymentSettings?->paypal_mode) === 'live')>Live</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="paypal_whid">Webhook ID</label>
                                <input type="text" class="form-control" id="paypal_whid" name="paypal_webhook_id"
                                       value="{{ old('paypal_webhook_id', $paymentSettings?->paypal_webhook_id) }}">
                            </div>
                        </div>

                        <h6 class="text-uppercase text-muted small">Generali</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="auto_attivazione" name="auto_attivazione_pagamento" value="1"
                                           @checked(old('auto_attivazione_pagamento', $paymentSettings?->auto_attivazione_pagamento ?? true))>
                                    <label class="form-check-label" for="auto_attivazione">Attiva automaticamente il socio dopo il pagamento online</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="valuta">Valuta</label>
                                <input type="text" class="form-control" id="valuta" name="valuta" maxlength="3"
                                       value="{{ old('valuta', $paymentSettings?->valuta ?? 'EUR') }}">
                            </div>
                        </div>
                        <button class="btn btn-primary" type="submit">Salva impostazioni pagamento</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ============ INVITA AL MINI-SITO (f.22) ============ --}}
        @if ($section === 'invito')
            @php
                $linkIscrizione = \Illuminate\Support\Facades\Route::has('pubblico.iscriviti')
                    ? route('pubblico.iscriviti', $associazione)
                    : url('/a/'.$associazione->slug.'/iscriviti');
                $qrUrl = \Illuminate\Support\Facades\Route::has('configurazioni.invito-qr')
                    ? route('configurazioni.invito-qr')
                    : null;
            @endphp
            <div class="card">
                <div class="card-header"><i class="bi bi-qr-code me-2"></i>Invita al tuo mini-sito</div>
                <div class="card-body">
                    @unless ($associazione->frontend_pubblico_attivo)
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Il mini-sito pubblico non è attivo: il link e il QR funzioneranno solo dopo l'attivazione.
                        </div>
                    @endunless

                    <p class="text-muted">Condividi questo link (o il QR code) per portare le persone direttamente
                        al modulo di iscrizione del tuo mini-sito <code>/a/{{ $associazione->slug }}</code>.</p>

                    <label class="form-label" for="link_invito">Link di iscrizione</label>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" id="link_invito" readonly value="{{ $linkIscrizione }}">
                        <button class="btn btn-outline-primary" type="button" id="btnCopiaInvito"
                                onclick="navigator.clipboard.writeText(document.getElementById('link_invito').value).then(() => { this.innerHTML = '<i class=&quot;bi bi-check-lg me-1&quot;></i>Copiato!'; setTimeout(() => { this.innerHTML = '<i class=&quot;bi bi-clipboard me-1&quot;></i>Copia link'; }, 2000); });">
                            <i class="bi bi-clipboard me-1"></i>Copia link
                        </button>
                    </div>

                    @if ($qrUrl)
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <img src="{{ $qrUrl }}" alt="QR code iscrizione {{ $associazione->nome }}"
                                     class="border rounded p-2 bg-white" style="width: 180px; height: 180px;">
                            </div>
                            <div class="col">
                                <p class="mb-2 text-muted">Il QR punta a <code>{{ $linkIscrizione }}</code>: stampalo su volantini e locandine.</p>
                                <a href="{{ $qrUrl }}?download=1" class="btn btn-primary">
                                    <i class="bi bi-download me-1"></i>Scarica QR (PNG)
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Modale sede: precompila in modifica, pulisce in creazione
    var modalSede = document.getElementById('modalSede');
    if (modalSede) {
        modalSede.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            var campi = ['id', 'nome', 'indirizzo', 'citta', 'provincia', 'cap', 'responsabile'];
            var sede = (btn && btn.dataset.sede) ? JSON.parse(btn.dataset.sede) : null;
            document.getElementById('modalSedeTitolo').textContent = sede ? 'Modifica sede' : 'Nuova sede';
            campi.forEach(function (campo) {
                var input = document.getElementById('sede_' + (campo === 'id' ? 'id' : campo)) || document.getElementById('sede_' + campo);
                if (input) input.value = sede ? (sede[campo] || '') : '';
            });
        });
        @if ($errors->any() && $section === 'sedi')
            new bootstrap.Modal(modalSede).show();
        @endif
    }
});
</script>
@endpush
