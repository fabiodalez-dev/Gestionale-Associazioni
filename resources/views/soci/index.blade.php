@extends('layouts.app')

@section('title', 'Soci - '.session('app_name', config('app.name')))

@section('content')
    @php
        $perStato = \App\Models\Socio::query()->selectRaw('stato, count(*) as n')->groupBy('stato')->pluck('n', 'stato');
        $totaleSoci = (int) $perStato->sum();
        $oggiSoci = now()->startOfDay();
        $inScadenzaSoci = \App\Models\Tessera::query()->where('stato', 'Attiva')->whereBetween('data_scadenza', [$oggiSoci, $oggiSoci->copy()->addDays(30)->endOfDay()])->count();
        $quoteAperte = \App\Models\Quota::query()->where('stato', '!=', 'Pagata')->where('anno', now()->year);
        $importoAperto = (float) (clone $quoteAperte)->sum('importo');
        $numeroAperte = (clone $quoteAperte)->count();
        $schede = collect([
            ['all', 'Tutti', $totaleSoci],
            [\App\Models\Socio::STATO_ATTIVO, 'Attivi', (int) ($perStato[\App\Models\Socio::STATO_ATTIVO] ?? 0)],
            ['pending', 'Da approvare', $pendingCount],
            [\App\Models\Socio::STATO_SOSPESO, 'Sospesi', (int) ($perStato[\App\Models\Socio::STATO_SOSPESO] ?? 0)],
            [\App\Models\Socio::STATO_RADIATO, 'Radiati', (int) ($perStato[\App\Models\Socio::STATO_RADIATO] ?? 0)],
            [\App\Models\Socio::STATO_TRASFERITO, 'Trasferiti', (int) ($perStato[\App\Models\Socio::STATO_TRASFERITO] ?? 0)],
            [\App\Models\Socio::STATO_DECEDUTO, 'Deceduti', (int) ($perStato[\App\Models\Socio::STATO_DECEDUTO] ?? 0)],
        ])->filter(fn ($t) => $t[2] > 0 || in_array($t[0], ['all', \App\Models\Socio::STATO_ATTIVO], true) || $filtri['status'] === $t[0]);
        $avanzatiAttivi = collect(['tessera_template', 'tessera_scadenza', 'gruppo_id', 'nucleo', 'has_tessera'])
            ->filter(fn ($k) => ($filtri[$k] ?? 'all') !== 'all' && filled($filtri[$k] ?? null))->count();
    @endphp

    <div class="page-head">
        <div>
            <div class="crumb">Anagrafica / <span>Soci</span></div>
            <h1>Soci</h1>
        </div>
        <div class="actions">
            <a href="{{ route('soci.import') }}" class="btn btn-outline-secondary">
                <i class="bi bi-upload"></i>Importa
            </a>
            <div class="btn-group">
                <a id="exportXlsx" href="{{ route('soci.export', array_merge($filtri, ['format' => 'xlsx'])) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-download"></i>Excel
                </a>
                <a id="exportCsv" href="{{ route('soci.export', array_merge($filtri, ['format' => 'csv'])) }}" class="btn btn-outline-secondary">
                    CSV
                </a>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('nuclei.index'))
                <a href="{{ route('nuclei.index') }}" class="btn btn-outline-secondary" title="Vista raggruppata per nucleo familiare">
                    <i class="bi bi-house-heart"></i>Per nucleo
                </a>
            @endif
            <a href="{{ route('soci.cestino') }}" class="btn btn-outline-secondary" title="Cestino">
                <i class="bi bi-trash"></i>Cestino
                @if ($cestinoCount > 0)
                    <span class="badge-count">{{ $cestinoCount }}</span>
                @endif
            </a>
            <a href="{{ route('soci.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i>Nuovo Socio
            </a>
        </div>
    </div>

    <div class="metrics-strip mb-4">
        <div class="metric-cell">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-people"></i></span>
                <span class="metric-label">Soci totali</span>
            </div>
            <div class="metric-value">{{ number_format($totaleSoci, 0, ',', '.') }}</div>
            <div class="metric-foot">{{ number_format((int) ($perStato[\App\Models\Socio::STATO_ATTIVO] ?? 0), 0, ',', '.') }} attivi</div>
        </div>
        <div class="metric-cell metric-warn">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-clock-history"></i></span>
                <span class="metric-label">Tessere in scadenza</span>
            </div>
            <div class="metric-value">{{ $inScadenzaSoci }}<span class="metric-delta text-warning">30 gg</span></div>
            <div class="metric-foot">
                @if (\Illuminate\Support\Facades\Route::has('campagna-rinnovi.index'))
                    <a href="{{ route('campagna-rinnovi.index') }}">Avvia i rinnovi</a>
                @else
                    nei prossimi 30 giorni
                @endif
            </div>
        </div>
        <a class="metric-cell metric-info" href="{{ route('soci.index', ['status' => 'pending', 'tessera_stato' => 'all']) }}">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-person-plus"></i></span>
                <span class="metric-label">Da approvare</span>
            </div>
            <div class="metric-value">{{ $pendingCount }}<span class="metric-delta text-info">nuove</span></div>
            <div class="metric-foot">pre-iscrizioni in attesa</div>
        </a>
        <div class="metric-cell metric-danger">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-exclamation-triangle"></i></span>
                <span class="metric-label">Quote non pagate {{ now()->year }}</span>
            </div>
            <div class="metric-value">€ {{ number_format($importoAperto, 0, ',', '.') }}<span class="metric-delta text-danger">{{ $numeroAperte }} quote</span></div>
            <div class="metric-foot">
                @if (\Illuminate\Support\Facades\Route::has('quote.index'))
                    <a href="{{ route('quote.index') }}">Vai alle quote</a>
                @endif
            </div>
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
    @if ($pendingCount > 0 && $filtri['status'] !== 'pending')
        <div class="alert alert-warning d-flex justify-content-between align-items-center gap-3">
            <span><i class="bi bi-hourglass-split me-1"></i>Ci sono <strong>{{ $pendingCount }}</strong> pre-iscrizioni in attesa di approvazione o pagamento.</span>
            <a href="{{ route('soci.index', ['status' => 'pending', 'tessera_stato' => 'all']) }}" class="btn btn-sm btn-warning">Vedi</a>
        </div>
    @endif

    <div class="list-card mb-4">
        {{-- Schede per stato: impostano il filtro "Stato" --}}
        <nav class="nav nav-tabs" aria-label="Filtra per stato">
            @foreach ($schede as [$valoreStato, $etichetta, $conteggio])
                <a href="{{ route('soci.index', array_merge($filtri, ['status' => $valoreStato])) }}"
                   class="nav-link {{ $filtri['status'] === $valoreStato ? 'active' : '' }}" data-stato="{{ $valoreStato }}">
                    {{ $etichetta }} <span class="badge-count">{{ number_format($conteggio, 0, ',', '.') }}</span>
                </a>
            @endforeach
        </nav>

        <div class="filters-wrap">
            <form id="filtersForm" method="GET" action="{{ route('soci.index') }}">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label class="form-label small mb-1" for="search">Ricerca</label>
                        <div class="search-field"><i class="bi bi-search"></i><input type="text" class="form-control" id="search" name="search"
                               value="{{ $filtri['search'] }}" placeholder="Nome, email, codice fiscale, n. socio…"></div>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="categoria_ids">Categorie</label>
                        <select class="form-select" id="categoria_ids" name="categoria_ids[]" multiple size="1">
                            @foreach ($categorie as $categoria)
                                <option value="{{ $categoria->id }}" @selected(in_array($categoria->id, (array) $filtri['categoria_ids'], true))>{{ $categoria->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="sede_id">Sede</label>
                        <select class="form-select" id="sede_id" name="sede_id">
                            <option value="all">Tutte</option>
                            @foreach ($sedi as $sede)
                                <option value="{{ $sede->id }}" @selected($filtri['sede_id'] === $sede->id)>{{ $sede->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="tessera_stato">Stato tessera</label>
                        <select class="form-select" id="tessera_stato" name="tessera_stato">
                            <option value="attive" @selected($filtri['tessera_stato'] === 'attive')>Attive</option>
                            <option value="scadute" @selected($filtri['tessera_stato'] === 'scadute')>Scadute</option>
                            <option value="all" @selected($filtri['tessera_stato'] === 'all')>Tutte</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2 d-flex gap-2">
                        <button type="button" class="btn btn-light flex-fill {{ $avanzatiAttivi > 0 ? 'active' : '' }}" data-bs-toggle="collapse" data-bs-target=".filtri-avanzati" aria-expanded="{{ $avanzatiAttivi > 0 ? 'true' : 'false' }}" aria-controls="filtriAvanzati">
                            <i class="bi bi-funnel"></i>Filtri avanzati
                            @if ($avanzatiAttivi > 0)<span class="badge-count badge-solid">{{ $avanzatiAttivi }}</span>@endif
                        </button>
                    </div>
                </div>
                <div class="collapse filtri-avanzati {{ $avanzatiAttivi > 0 || $filtri['status'] !== 'all' ? 'show' : '' }}" id="filtriAvanzati">
                    <div class="row g-2 align-items-end pt-2">
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="status">Stato</label>
                        <select class="form-select" id="status" name="status">
                            <option value="all">Tutti</option>
                            <option value="pending" @selected($filtri['status'] === 'pending')>Pre-iscrizioni (in attesa)</option>
                            @foreach (\App\Models\Socio::STATI as $stato)
                                <option value="{{ $stato }}" @selected($filtri['status'] === $stato)>{{ $stato }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="has_tessera">Tessera</label>
                        <select class="form-select" id="has_tessera" name="has_tessera">
                            <option value="all" @selected($filtri['has_tessera'] === 'all')>Tutti</option>
                            <option value="yes" @selected($filtri['has_tessera'] === 'yes')>Con</option>
                            <option value="no" @selected($filtri['has_tessera'] === 'no')>Senza</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="tessera_template">Template tessera</label>
                        <select class="form-select" id="tessera_template" name="tessera_template">
                            <option value="all">Tutti</option>
                            @foreach ($templatesTessera as $template)
                                <option value="{{ $template }}" @selected($filtri['tessera_template'] === $template)>{{ $template }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="tessera_scadenza">Scadenza tessera</label>
                        <select class="form-select" id="tessera_scadenza" name="tessera_scadenza">
                            <option value="all" @selected($filtri['tessera_scadenza'] === 'all')>Tutte</option>
                            <option value="in_scadenza" @selected($filtri['tessera_scadenza'] === 'in_scadenza')>In scadenza</option>
                            <option value="scadute" @selected($filtri['tessera_scadenza'] === 'scadute')>Scadute</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="gruppo_id">Gruppo dinamico</label>
                        <select class="form-select" id="gruppo_id" name="gruppo_id">
                            <option value="all">Nessuno</option>
                            @foreach ($gruppi as $gruppo)
                                <option value="{{ $gruppo->id }}" @selected($filtri['gruppo_id'] === $gruppo->id)>{{ $gruppo->nome_gruppo }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Filtro nucleo familiare (f.20 piano "Gestione 2" — modulo membri) --}}
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="nucleo">Nucleo familiare</label>
                        <select class="form-select" id="nucleo" name="nucleo">
                            <option value="all" @selected(($filtri['nucleo'] ?? 'all') === 'all')>Tutti</option>
                            <option value="con" @selected(($filtri['nucleo'] ?? '') === 'con')>Con nucleo</option>
                            <option value="senza" @selected(($filtri['nucleo'] ?? '') === 'senza')>Senza nucleo</option>
                            @foreach (\App\Models\Nucleo::query()->orderBy('nome')->get(['id', 'nome']) as $nucleoOpzione)
                                <option value="{{ $nucleoOpzione->id }}" @selected(($filtri['nucleo'] ?? '') === $nucleoOpzione->id)>{{ $nucleoOpzione->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center pt-3">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i>Applica</button>
                        <a href="{{ route('soci.index') }}" class="btn btn-sm btn-outline-secondary">Azzera filtri</a>
                    </div>
                </div>
            </form>
            <div class="collapse filtri-avanzati {{ $avanzatiAttivi > 0 || $filtri['status'] !== 'all' ? 'show' : '' }}" id="filtriPreset">
                {{-- Preset filtri salvati (spec §11) --}}
                <div class="preset-row">
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
        </div>

    {{-- Barra azioni in blocco (f.2 piano "Gestione 2" — modulo operativita) --}}
    @includeIf('operativita._azioni-blocco')

    <div id="sociResults">
        @include('soci.partials.lista', ['soci' => $soci, 'associazione' => $associazione, 'isSuperAdmin' => $isSuperAdmin])
    </div>
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
