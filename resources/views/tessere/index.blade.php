@extends('layouts.app')

@section('title', 'Gestione Tessere')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-credit-card-2-front me-2"></i>Gestione Tessere</h1>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('tessere.templates.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-file-text me-1"></i>Template
        </a>
        <a href="{{ route('tessere.checkin') }}" class="btn btn-outline-secondary">
            <i class="bi bi-qr-code-scan me-1"></i>Check-in
        </a>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#generaMassivoModal">
            <i class="bi bi-stack me-1"></i>Genera Tessere {{ $annoCorrente }}
        </button>
        <button type="button" class="btn btn-primary" id="btnNuovaTessera">
            <i class="bi bi-plus-lg me-1"></i>Nuova Tessera
        </button>
    </div>
</div>

{{-- Filtri --}}
<form method="GET" action="{{ route('tessere.index') }}" id="filtriForm" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label" for="filtroAnno">Anno</label>
            <select class="form-select" id="filtroAnno" name="anno">
                @foreach ($anni as $a)
                    <option value="{{ $a }}" @selected($a === $anno)>{{ $a }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-5">
            <label class="form-label" for="filtroSearch">Ricerca</label>
            <input type="text" class="form-control" id="filtroSearch" name="search" value="{{ $search }}"
                   placeholder="Nome, cognome, numero socio o tessera...">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="filtroStato">Stato</label>
            <select class="form-select" id="filtroStato" name="stato">
                <option value="all" @selected($stato === 'all')>Tutti</option>
                @foreach (\App\Models\Tessera::STATI as $s)
                    <option value="{{ $s }}" @selected($stato === $s)>{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-funnel me-1"></i>Filtra</button>
        </div>
    </div>
</form>

{{-- Grafici aggregati --}}
@if ($aggregati->isNotEmpty())
<div class="row mb-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Tesserati per Tipo</div>
            <div class="card-body"><canvas id="chartTesserati" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Ricavi per Tipo (teorici)</div>
            <div class="card-body"><canvas id="chartRicavi" height="200"></canvas></div>
        </div>
    </div>
</div>
@endif

{{-- Lista --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Tessere {{ $anno }}</span>
        <span class="badge bg-primary" id="countBadge">{{ $tessere->count() }}</span>
    </div>
    <div class="card-body" id="risultatiContainer">
        @include('tessere.partials.risultati', ['tessere' => $tessere, 'sogliaInScadenza' => $sogliaInScadenza])
    </div>
</div>

{{-- Modal crea/modifica tessera --}}
<div class="modal fade" id="tesseraModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('tessere.store') }}" id="tesseraForm">
            @csrf
            <input type="hidden" name="_method" value="POST" id="tesseraFormMethod">
            <div class="modal-header">
                <h5 class="modal-title" id="tesseraModalTitle">Nuova Tessera</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="campoSocio">Socio <span class="text-danger">*</span></label>
                    <select class="form-select" id="campoSocio" name="socio_id" required>
                        <option value="">-- Seleziona socio --</option>
                        @foreach ($sociAttivi as $socio)
                            <option value="{{ $socio->id }}">{{ $socio->cognome }} {{ $socio->nome }} ({{ $socio->numero_socio }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campoNumero">Numero tessera <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="campoNumero" name="numero_tessera" maxlength="50" required
                               value="{{ $numeroSuggerito }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campoAnno">Anno validità <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="campoAnno" name="anno_validita" min="2000" max="2100" required
                               value="{{ $annoCorrente }}">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campoEmissione">Data emissione <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="campoEmissione" name="data_emissione" required
                               value="{{ now()->toDateString() }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campoScadenza">Data scadenza</label>
                        <input type="date" class="form-control" id="campoScadenza" name="data_scadenza"
                               value="{{ $annoCorrente }}-12-31">
                        <div class="form-text">Se vuota, calcolata dal tipo scadenza.</div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campoStato">Stato</label>
                        <select class="form-select" id="campoStato" name="stato">
                            <option value="Attiva">Attiva</option>
                            <option value="Sospesa">Sospesa</option>
                            <option value="Annullata">Annullata</option>
                        </select>
                        <div class="form-text">"Scaduta" è derivato dalla data di scadenza.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tipo scadenza</label>
                        <div>
                            <span class="badge bg-light text-dark border">{{ ucfirst($tipoScadenza) }}</span>
                            <div class="form-text">Impostazione dell'associazione (non modificabile).</div>
                        </div>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="campoEvento">Evento di creazione</label>
                    <select class="form-select" id="campoEvento" name="evento_creazione_id">
                        <option value="">-- Nessun evento --</option>
                        @foreach ($eventi as $evento)
                            <option value="{{ $evento->id }}">{{ $evento->titolo }} ({{ $evento->data_evento?->format('d/m/Y') }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">Salva</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal generazione massiva --}}
<div class="modal fade" id="generaMassivoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('tessere.genera-massivo') }}">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Genera Tessere {{ $annoCorrente }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <p>Verrà generata una tessera per ogni socio <strong>Attivo</strong> che non ha ancora
                   una tessera per l'anno <strong>{{ $annoCorrente }}</strong>.</p>
                <input type="hidden" name="anno_validita" value="{{ $annoCorrente }}">
                <div class="mb-2">
                    <label class="form-label" for="campoEventoMassivo">Evento di creazione (opzionale)</label>
                    <select class="form-select" id="campoEventoMassivo" name="evento_creazione_id">
                        <option value="">-- Nessun evento --</option>
                        @foreach ($eventi as $evento)
                            <option value="{{ $evento->id }}">{{ $evento->titolo }} ({{ $evento->data_evento?->format('d/m/Y') }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-stack me-1"></i>Genera</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
(function() {
    'use strict';

    var aggregati = @json($aggregati);
    var indexUrl = @json(route('tessere.index'));
    var storeUrl = @json(route('tessere.store'));
    var brand = '#FF7B11';

    // ---- Grafici ----
    if (aggregati.length && window.Chart) {
        var labels = aggregati.map(function(r) { return r.tipo; });
        new Chart(document.getElementById('chartTesserati'), {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: 'Tesserati', data: aggregati.map(function(r) { return r.cnt; }), backgroundColor: brand }] },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
        new Chart(document.getElementById('chartRicavi'), {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: 'Ricavi (€)', data: aggregati.map(function(r) { return r.ricavi; }), backgroundColor: '#003366' }] },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }

    // ---- Ricerca AJAX (debounce 300ms) ----
    var searchInput = document.getElementById('filtroSearch');
    var annoSelect = document.getElementById('filtroAnno');
    var statoSelect = document.getElementById('filtroStato');
    var container = document.getElementById('risultatiContainer');
    var countBadge = document.getElementById('countBadge');
    var debounceTimer = null;

    function refreshList() {
        var params = new URLSearchParams({
            anno: annoSelect.value,
            search: searchInput.value,
            stato: statoSelect.value
        });

        fetch(indexUrl + '?' + params.toString() + '&ajax=1', { headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                container.innerHTML = data.html; // markup renderizzato (ed escapato) server-side
                if (countBadge) countBadge.textContent = data.count;
                bindEditButtons();
                history.replaceState(null, '', indexUrl + '?' + params.toString());
            })
            .catch(function() { /* fallback: submit classico del form */ });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(refreshList, 300);
    });
    annoSelect.addEventListener('change', refreshList);
    statoSelect.addEventListener('change', refreshList);

    // ---- Modal crea/modifica ----
    var modalEl = document.getElementById('tesseraModal');
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var form = document.getElementById('tesseraForm');
    var methodField = document.getElementById('tesseraFormMethod');
    var title = document.getElementById('tesseraModalTitle');

    document.getElementById('btnNuovaTessera').addEventListener('click', function() {
        form.action = storeUrl;
        methodField.value = 'POST';
        title.textContent = 'Nuova Tessera';
        form.reset();
        if (modal) modal.show();
    });

    function bindEditButtons() {
        document.querySelectorAll('.btn-edit-tessera').forEach(function(btn) {
            btn.addEventListener('click', function() {
                form.action = btn.dataset.updateUrl;
                methodField.value = 'PUT';
                title.textContent = 'Modifica Tessera ' + btn.dataset.numero;
                document.getElementById('campoSocio').value = btn.dataset.socioId || '';
                document.getElementById('campoNumero').value = btn.dataset.numero || '';
                document.getElementById('campoAnno').value = btn.dataset.anno || '';
                document.getElementById('campoEmissione').value = btn.dataset.emissione || '';
                document.getElementById('campoScadenza').value = btn.dataset.scadenza || '';
                document.getElementById('campoStato').value = ['Attiva','Sospesa','Annullata'].indexOf(btn.dataset.stato) !== -1 ? btn.dataset.stato : 'Attiva';
                document.getElementById('campoEvento').value = btn.dataset.eventoId || '';
                if (modal) modal.show();
            });
        });
    }

    bindEditButtons();
})();
</script>
@endpush
