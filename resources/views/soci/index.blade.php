@extends('layouts.app')

@section('title', 'Soci - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-people me-2"></i>Soci</h1>
            <p class="text-muted mb-0">
                <span id="sociCount" class="badge bg-primary">{{ $soci->total() }}</span>
                {{ $soci->total() === 1 ? 'socio' : 'soci' }} trovati
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('soci.create') }}" class="btn btn-primary">
                <i class="bi bi-person-plus me-1"></i>Nuovo Socio
            </a>
            <div class="btn-group">
                <a id="exportXlsx" href="{{ route('soci.export', array_merge($filtri, ['format' => 'xlsx'])) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </a>
                <a id="exportCsv" href="{{ route('soci.export', array_merge($filtri, ['format' => 'csv'])) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-filetype-csv me-1"></i>CSV
                </a>
            </div>
            <a href="{{ route('soci.import') }}" class="btn btn-outline-secondary">
                <i class="bi bi-upload me-1"></i>Importa
            </a>
            <a href="{{ route('soci.cestino') }}" class="btn btn-outline-secondary">
                <i class="bi bi-trash me-1"></i>Cestino
                @if ($cestinoCount > 0)
                    <span class="badge bg-secondary">{{ $cestinoCount }}</span>
                @endif
            </a>
        </div>
    </div>

    @if (session('payment_link'))
        <div class="alert alert-info">
            <i class="bi bi-link-45deg me-1"></i>Link di pagamento da inviare al socio:
            <code id="paymentLink">{{ session('payment_link') }}</code>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2"
                    onclick="navigator.clipboard.writeText(document.getElementById('paymentLink').textContent)">
                <i class="bi bi-clipboard"></i> Copia
            </button>
        </div>
    @endif

    {{-- Banner pre-iscrizioni in attesa (spec §1.12) --}}
    @if ($pendingCount > 0)
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hourglass-split me-1"></i>Ci sono <strong>{{ $pendingCount }}</strong> pre-iscrizioni in attesa di approvazione o pagamento.</span>
            <a href="{{ route('soci.index', ['status' => 'pending', 'tessera_stato' => 'all']) }}" class="btn btn-sm btn-warning">Vedi</a>
        </div>
    @endif

    {{-- Filtri (spec §1.9) --}}
    <div class="card mb-3">
        <div class="card-body">
            <form id="filtersForm" method="GET" action="{{ route('soci.index') }}">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1" for="search">Ricerca</label>
                        <input type="text" class="form-control form-control-sm" id="search" name="search"
                               value="{{ $filtri['search'] }}" placeholder="Nome, email, CF, numero socio...">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="status">Stato</label>
                        <select class="form-select form-select-sm" id="status" name="status">
                            <option value="all">Tutti</option>
                            <option value="pending" @selected($filtri['status'] === 'pending')>Pre-iscrizioni (in attesa)</option>
                            @foreach (\App\Models\Socio::STATI as $stato)
                                <option value="{{ $stato }}" @selected($filtri['status'] === $stato)>{{ $stato }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="sede_id">Sede</label>
                        <select class="form-select form-select-sm" id="sede_id" name="sede_id">
                            <option value="all">Tutte</option>
                            @foreach ($sedi as $sede)
                                <option value="{{ $sede->id }}" @selected($filtri['sede_id'] === $sede->id)>{{ $sede->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="categoria_ids">Categorie</label>
                        <select class="form-select form-select-sm" id="categoria_ids" name="categoria_ids[]" multiple size="1">
                            @foreach ($categorie as $categoria)
                                <option value="{{ $categoria->id }}" @selected(in_array($categoria->id, (array) $filtri['categoria_ids'], true))>{{ $categoria->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label small mb-1" for="has_tessera">Tessera</label>
                        <select class="form-select form-select-sm" id="has_tessera" name="has_tessera">
                            <option value="all" @selected($filtri['has_tessera'] === 'all')>Tutti</option>
                            <option value="yes" @selected($filtri['has_tessera'] === 'yes')>Con</option>
                            <option value="no" @selected($filtri['has_tessera'] === 'no')>Senza</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="tessera_stato">Stato tessera</label>
                        <select class="form-select form-select-sm" id="tessera_stato" name="tessera_stato">
                            <option value="attive" @selected($filtri['tessera_stato'] === 'attive')>Attive</option>
                            <option value="scadute" @selected($filtri['tessera_stato'] === 'scadute')>Scadute</option>
                            <option value="all" @selected($filtri['tessera_stato'] === 'all')>Tutte</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="tessera_template">Template tessera</label>
                        <select class="form-select form-select-sm" id="tessera_template" name="tessera_template">
                            <option value="all">Tutti</option>
                            @foreach ($templatesTessera as $template)
                                <option value="{{ $template }}" @selected($filtri['tessera_template'] === $template)>{{ $template }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="tessera_scadenza">Scadenza tessera</label>
                        <select class="form-select form-select-sm" id="tessera_scadenza" name="tessera_scadenza">
                            <option value="all" @selected($filtri['tessera_scadenza'] === 'all')>Tutte</option>
                            <option value="in_scadenza" @selected($filtri['tessera_scadenza'] === 'in_scadenza')>In scadenza</option>
                            <option value="scadute" @selected($filtri['tessera_scadenza'] === 'scadute')>Scadute</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="gruppo_id">Gruppo dinamico</label>
                        <select class="form-select form-select-sm" id="gruppo_id" name="gruppo_id">
                            <option value="all">Nessuno</option>
                            @foreach ($gruppi as $gruppo)
                                <option value="{{ $gruppo->id }}" @selected($filtri['gruppo_id'] === $gruppo->id)>{{ $gruppo->nome_gruppo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filtra</button>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="{{ route('soci.index') }}" class="btn btn-sm btn-outline-secondary w-100">Azzera</a>
                    </div>
                </div>
            </form>

            {{-- Preset filtri salvati (spec §11) --}}
            <hr class="my-2">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <form method="GET" action="{{ route('soci.index') }}" class="d-flex gap-2 align-items-center">
                    <label class="small text-muted" for="preset_id">Preset:</label>
                    <select class="form-select form-select-sm" id="preset_id" name="preset_id" onchange="this.form.submit()" style="width:auto">
                        <option value="">— Nessuno —</option>
                        @foreach ($presets as $preset)
                            <option value="{{ $preset->id }}" @selected($presetCorrente?->id === $preset->id)>
                                {{ $preset->name }}{{ $preset->isCondiviso() ? ' (condiviso)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </form>
                <form method="POST" action="{{ route('soci.filtri.store') }}" class="d-flex gap-2 align-items-center">
                    @csrf
                    @foreach ($filtri as $chiave => $valore)
                        @if (is_array($valore))
                            @foreach ($valore as $v)
                                <input type="hidden" name="{{ $chiave }}[]" value="{{ $v }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $chiave }}" value="{{ $valore }}">
                        @endif
                    @endforeach
                    <input type="text" class="form-control form-control-sm" name="preset_name" placeholder="Nome preset" required style="width:160px">
                    <div class="form-check form-check-inline small">
                        <input class="form-check-input" type="checkbox" name="condiviso" id="condiviso" value="1">
                        <label class="form-check-label" for="condiviso">Condiviso</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-bookmark-plus"></i> Salva filtri</button>
                </form>
                @if ($presetCorrente)
                    <form method="POST" action="{{ route('soci.filtri.destroy', $presetCorrente) }}"
                          onsubmit="return confirm('Eliminare il preset «{{ $presetCorrente->name }}»?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-bookmark-x"></i> Elimina preset</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div id="sociResults">
        @include('soci.partials.lista', ['soci' => $soci, 'associazione' => $associazione, 'isSuperAdmin' => $isSuperAdmin])
    </div>

    {{-- Modal conferma pagamento (spec §2.4) --}}
    <div class="modal fade" id="confermaPagamentoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" id="confermaPagamentoForm" action="#">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Conferma pagamento — <span id="cpSocioNome"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="cpImporto">Importo (€)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="cpImporto" name="importo" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cpMetodo">Metodo</label>
                            <select class="form-select" id="cpMetodo" name="metodo" required>
                                <option value="contanti">Contanti</option>
                                <option value="bonifico">Bonifico</option>
                                <option value="carta">Carta</option>
                                <option value="altro">Altro</option>
                                @if ($associazione?->paymentGatewaySetting?->stripe_enabled)
                                    <option value="stripe">Stripe (link online)</option>
                                @endif
                                @if ($associazione?->paymentGatewaySetting?->paypal_enabled)
                                    <option value="paypal">PayPal (link online)</option>
                                @endif
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cpData">Data pagamento</label>
                            <input type="date" class="form-control" id="cpData" name="data_pagamento" value="{{ now()->toDateString() }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cpTipo">Tipo</label>
                            <input type="text" class="form-control" id="cpTipo" name="tipo" value="Quota Associativa">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Conferma</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('filtersForm');
    const results = document.getElementById('sociResults');
    const counter = document.getElementById('sociCount');
    const search = document.getElementById('search');
    const gruppo = document.getElementById('gruppo_id');
    let debounceTimer = null;

    function fetchResults() {
        const params = new URLSearchParams(new FormData(form));
        params.set('ajax', '1');

        fetch('{{ route('soci.index') }}?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                results.innerHTML = data.html;
                counter.textContent = data.count;
                params.delete('ajax');
                history.replaceState(null, '', '{{ route('soci.index') }}?' + params.toString());
                // Aggiorna i link di export con i filtri correnti (spec §1.11)
                const xlsx = new URLSearchParams(params); xlsx.set('format', 'xlsx');
                const csv = new URLSearchParams(params); csv.set('format', 'csv');
                document.getElementById('exportXlsx').href = '{{ route('soci.export') }}?' + xlsx.toString();
                document.getElementById('exportCsv').href = '{{ route('soci.export') }}?' + csv.toString();
            })
            .catch(() => form.submit());
    }

    function toggleGruppoEsclusivo() {
        // Gruppo dinamico esclusivo: disabilita ricerca e stato (spec §1.9)
        const attivo = gruppo.value !== 'all' && gruppo.value !== '';
        search.disabled = attivo;
        document.getElementById('status').disabled = attivo;
    }

    if (form) {
        form.addEventListener('submit', function (e) { e.preventDefault(); fetchResults(); });
        search.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(fetchResults, 300);
        });
        form.querySelectorAll('select').forEach(function (sel) {
            sel.addEventListener('change', function () { toggleGruppoEsclusivo(); fetchResults(); });
        });
        toggleGruppoEsclusivo();
    }

    // Modal conferma pagamento: precompila importo e action dal bottone
    const cpModal = document.getElementById('confermaPagamentoModal');
    if (cpModal) {
        cpModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('confermaPagamentoForm').action = btn.dataset.action;
            document.getElementById('cpSocioNome').textContent = btn.dataset.socioNome || '';
            document.getElementById('cpImporto').value = btn.dataset.importo || '0';
        });
    }
})();
</script>
@endpush
