@extends('layouts.app')

@section('title', 'Quote Associative')

@php
    $badgeClass = [
        \App\Models\Quota::STATO_PAGATA => 'success',
        \App\Models\Quota::STATO_DA_PAGARE => 'info',
        \App\Models\Quota::STATO_IN_SCADENZA => 'warning',
        \App\Models\Quota::STATO_SCADUTA => 'danger',
    ];
    $hasOnlineGateway = $hasStripe || $hasPayPal;
@endphp

@section('content')
@php
    $euro = fn (float $v, int $dec = 2) => '€ '.number_format($v, $dec, ',', '.');
    $metodi = [
        'contanti' => ['CASH', 'Contanti'], 'bonifico' => ['BON', 'Bonifico'], 'carta' => ['CARD', 'Carta'],
        'stripe' => ['STR', 'Stripe'], 'paypal' => ['PP', 'PayPal'], 'sepa' => ['SEPA', 'SEPA'], 'satispay' => ['SP', 'Satispay'], 'altro' => ['ALT', 'Altro'],
    ];
    $schede = ['Tutti' => 'Tutte', \App\Models\Quota::STATO_PAGATA => 'Pagate', \App\Models\Quota::STATO_DA_PAGARE => 'Da pagare', \App\Models\Quota::STATO_IN_SCADENZA => 'In scadenza', \App\Models\Quota::STATO_SCADUTA => 'Scadute'];
    $stripeOk = $gateway?->isStripeOperational() ?? false;
    $paypalOk = $gateway?->isPayPalOperational() ?? false;
@endphp

<div class="page-head">
    <div>
        <div class="crumb">Economia / <span>Quote e pagamenti</span></div>
        <h1>Quote e pagamenti</h1>
    </div>
    <div class="actions">
        <form method="GET" action="{{ route('quote.index') }}" class="m-0">
            <input type="hidden" name="status" value="{{ $filtroStato }}">
            <label class="visually-hidden" for="filterYear">Anno</label>
            <div class="filter-field">
                <span><i class="bi bi-calendar3 me-1"></i>Anno</span>
                <select name="year" id="filterYear" class="form-select" onchange="this.form.submit()">
                    <option value="Tutti">Tutti</option>
                    @foreach ($anni as $anno)
                        <option value="{{ $anno }}" @selected((string) $filtroAnno === (string) $anno)>{{ $anno }}</option>
                    @endforeach
                </select>
            </div>
        </form>
        <a href="{{ route('quote.export', ['year' => $filtroAnno, 'status' => $filtroStato]) }}" class="btn btn-outline-secondary"><i class="bi bi-download"></i>CSV</a>
        @if (Route::has('solleciti-quote.index'))
            <a href="{{ route('solleciti-quote.index') }}" class="btn btn-outline-secondary"><i class="bi bi-bell"></i>Solleciti</a>
        @endif
        @can('create', \App\Models\Quota::class)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quotaModal" id="btnNuovaQuota">
                <i class="bi bi-plus-lg"></i>Nuova quota
            </button>
        @endcan
    </div>
</div>

<div class="kpi-strip mb-4">
    <div class="kpi-cell">
        <div><div class="kpi-big">{{ $euro($kpi['incassato'], 0) }}</div><div class="kpi-name">Incassato {{ $kpi['anno'] }}</div>
            @if ($kpi['variazione'] !== null)
                <div class="kpi-note {{ $kpi['variazione'] >= 0 ? 'metric-up' : 'metric-down' }}">{{ $kpi['variazione'] >= 0 ? '+' : '' }}{{ number_format($kpi['variazione'], 1, ',', '.') }}% sul {{ $kpi['anno'] - 1 }}</div>
            @else
                <div class="kpi-note text-muted">{{ $kpi['pagate'] }} {{ $kpi['pagate'] === 1 ? 'quota pagata' : 'quote pagate' }}</div>
            @endif
        </div>
        <span class="icon-tile tone-ok"><i class="bi bi-currency-euro"></i></span>
    </div>
    <div class="kpi-cell">
        <div><div class="kpi-big">{{ $euro($kpi['da_incassare'], 0) }}</div><div class="kpi-name">Da incassare</div><div class="kpi-note text-warning">{{ $kpi['aperte'] }} {{ $kpi['aperte'] === 1 ? 'quota aperta' : 'quote aperte' }}</div></div>
        <span class="icon-tile tone-1"><i class="bi bi-clock-history"></i></span>
    </div>
    <div class="kpi-cell">
        <div><div class="kpi-big">{{ $euro($kpi['scadute'], 0) }}</div><div class="kpi-name">Scadute non pagate</div>
            <div class="kpi-note text-danger">
                @if ($kpi['numero_scadute'] > 0 && Route::has('solleciti-quote.index'))
                    <a class="text-danger" href="{{ route('solleciti-quote.index') }}">{{ $kpi['numero_scadute'] }} da sollecitare</a>
                @else
                    {{ $kpi['numero_scadute'] }} scadute
                @endif
            </div>
        </div>
        <span class="icon-tile tone-danger"><i class="bi bi-exclamation-triangle"></i></span>
    </div>
    <div class="kpi-cell">
        <div><div class="kpi-big">{{ number_format($kpi['pagate'], 0, ',', '.') }}</div><div class="kpi-name">Pagamenti {{ $kpi['anno'] }}</div><div class="kpi-note text-info">{{ $kpi['online'] }} online o con carta</div></div>
        <span class="icon-tile tone-2"><i class="bi bi-receipt"></i></span>
    </div>
</div>

@if (session('payment_link'))
    <div class="alert alert-success">
        <div class="fw-semibold mb-2"><i class="bi bi-link-45deg me-1"></i>Link pagamento generato — invialo al socio:</div>
        <div class="input-group">
            <input type="text" class="form-control" id="paymentLinkInput" value="{{ session('payment_link') }}" readonly>
            <button class="btn btn-outline-secondary" type="button" id="copyPaymentLink"><i class="bi bi-clipboard me-1"></i>Copia</button>
        </div>
    </div>
@endif

<div class="row g-4">
    <div class="col-xl-8">
        <div class="list-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-4 pt-3 pb-2">
                <h2 class="h6 m-0">Transazioni</h2>
                <nav class="segmented" aria-label="Filtra per stato">
                    @foreach ($schede as $valore => $etichetta)
                        <a href="{{ route('quote.index', ['year' => $filtroAnno, 'status' => $valore]) }}" class="{{ $filtroStato === $valore ? 'active' : '' }}">
                            {{ $etichetta }} <span class="badge-count">{{ $conteggiStato[$valore] ?? 0 }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
            <input type="hidden" id="filterStatus" value="{{ $filtroStato }}">

            {{-- Viste salvate (f.5 piano "Gestione 2" — modulo operativita) --}}
            <div class="px-4 pb-2">
                @includeIf('operativita._viste-salvate', ['scope' => 'quote', 'vsRoute' => 'quote.index', 'vsRouteParams' => []])
            </div>

            @if ($quote->isEmpty())
                <div class="empty-state">
                    <span class="icon-tile tone-4"><i class="bi bi-wallet2"></i></span>
                    <h3>Nessuna quota trovata</h3>
                    <p class="mb-0">Cambia anno o stato, oppure crea una nuova quota.</p>
                </div>
            @else
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>N.</th>
                                <th>Socio</th>
                                <th>Causale</th>
                                <th>Metodo</th>
                                <th class="text-end">Importo</th>
                                <th>Stato</th>
                                <th class="text-end"><span class="visually-hidden">Azioni</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quote as $quota)
                                @php
                                    $stato = $quota->statoCalcolato();
                                    $nomeSocio = $quota->socio ? trim($quota->socio->cognome.' '.$quota->socio->nome) : '—';
                                    $metodo = $metodi[strtolower((string) $quota->metodo_pagamento)] ?? null;
                                @endphp
                                <tr>
                                    <td class="mono"><span class="text-primary">Q-{{ strtoupper(substr(str_replace('-', '', $quota->id), -5)) }}</span></td>
                                    <td>
                                        <div class="who-cell">
                                            <span class="avatar avatar-sm tone-{{ abs(crc32((string) $quota->socio_id)) % 6 }}">{{ $quota->socio ? mb_strtoupper(mb_substr($quota->socio->nome, 0, 1).mb_substr($quota->socio->cognome, 0, 1)) : '?' }}</span>
                                            @if ($quota->socio && ! $quota->socio->trashed())
                                                <a class="who-name" href="{{ route('soci.show', $quota->socio) }}">{{ $nomeSocio }}</a>
                                            @else
                                                <span class="who-name">{{ $nomeSocio }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-body">{{ $quota->tipo }} {{ $quota->anno }}</div>
                                        <div class="who-sub">{{ $quota->data_pagamento ? 'pagata il '.$quota->data_pagamento->format('d/m/Y') : 'scade il '.$quota->data_scadenza?->format('d/m/Y') }}</div>
                                    </td>
                                    <td>
                                        @if ($metodo)
                                            <span class="method-tag">{{ $metodo[0] }}</span> <span class="small">{{ $metodo[1] }}</span>
                                        @elseif ($quota->metodo_pagamento)
                                            <span class="small">{{ $quota->metodo_pagamento }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold text-body text-nowrap">{{ $euro((float) $quota->importo) }}</td>
                                    <td><span class="badge bg-{{ $badgeClass[$stato] ?? 'secondary' }}">{{ $stato }}</span></td>
                                    <td class="text-end text-nowrap">
                                        @include('quote.partials.azioni', ['quota' => $quota, 'stato' => $stato, 'hasOnlineGateway' => $hasOnlineGateway])
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Card mobile --}}
                <div class="d-md-none p-3">
                    @foreach ($quote as $quota)
                        @php $stato = $quota->statoCalcolato(); @endphp
                        <div class="card mb-2">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold text-body">{{ $quota->socio ? trim($quota->socio->cognome.' '.$quota->socio->nome) : '—' }}</div>
                                        <div class="small text-muted">{{ $quota->tipo }} — {{ $quota->anno }}</div>
                                    </div>
                                    <span class="badge bg-{{ $badgeClass[$stato] ?? 'secondary' }}">{{ $stato }}</span>
                                </div>
                                <div class="mt-2 d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-semibold text-body">{{ $euro((float) $quota->importo) }}</span>
                                        <span class="small text-muted ms-2">scad. {{ $quota->data_scadenza?->format('d/m/Y') }}</span>
                                    </div>
                                    <div>
                                        @include('quote.partials.azioni', ['quota' => $quota, 'stato' => $stato, 'hasOnlineGateway' => $hasOnlineGateway])
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($quote->hasPages())
                    <div class="list-foot">{{ $quote->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    <div class="col-xl-4 d-flex flex-column gap-4">
        <div class="card">
            <div class="card-head pb-3">
                <h2>Listino quote {{ $kpi['anno'] }}</h2>
                @if (Route::has('tipi-socio.index'))
                    <a href="{{ route('tipi-socio.index') }}">Modifica</a>
                @endif
            </div>
            <div class="px-4 pb-4 d-flex flex-column gap-2">
                @if ($costoBase !== null)
                    <div class="price-row">
                        <div><div class="text-body fw-medium">Quota standard</div><div class="who-sub">Associazione · usata se il tipo socio non ha un prezzo</div></div>
                        <span class="price">{{ $euro((float) $costoBase) }}</span>
                    </div>
                @endif
                @forelse ($listino as $tipo)
                    <div class="price-row">
                        <div><div class="text-body fw-medium">{{ $tipo->nome }}</div><div class="who-sub">Tipo socio</div></div>
                        <span class="price">{{ $tipo->costo_tessera !== null ? $euro((float) $tipo->costo_tessera) : '—' }}</span>
                    </div>
                @empty
                    @if ($costoBase === null)
                        <p class="text-muted small mb-0">Nessun prezzo definito. Imposta il costo della tessera nei tipi socio o nelle impostazioni dell'associazione.</p>
                    @endif
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-head pb-3">
                <h2>Metodi di incasso</h2>
                @can('gestisciImpostazioni', \App\Models\Quota::class)
                    <a href="{{ route('quote.impostazioni.edit') }}">Impostazioni</a>
                @endcan
            </div>
            <ul class="panel-list">
                <li>
                    <div class="who-cell"><span class="method-tile">STR</span><div><div class="text-body fw-medium">Carte e Apple Pay</div><div class="who-sub">Stripe</div></div></div>
                    <span class="badge {{ $stripeOk ? 'bg-success' : 'bg-secondary' }}">{{ $stripeOk ? 'Attivo' : 'Da collegare' }}</span>
                </li>
                <li>
                    <div class="who-cell"><span class="method-tile">PP</span><div><div class="text-body fw-medium">PayPal</div><div class="who-sub">{{ $gateway?->paypal_mode === 'live' ? 'Conto reale' : 'Ambiente di prova' }}</div></div></div>
                    <span class="badge {{ $paypalOk ? 'bg-success' : 'bg-secondary' }}">{{ $paypalOk ? 'Attivo' : 'Da collegare' }}</span>
                </li>
                <li>
                    <div class="who-cell"><span class="method-tile">BON</span><div><div class="text-body fw-medium">Bonifico</div><div class="who-sub">Registrato a mano dalla segreteria</div></div></div>
                    <span class="badge bg-success">Attivo</span>
                </li>
                <li>
                    <div class="who-cell"><span class="method-tile">CASH</span><div><div class="text-body fw-medium">Contanti</div><div class="who-sub">Con ricevuta PDF</div></div></div>
                    <span class="badge bg-success">Attivo</span>
                </li>
            </ul>
        </div>
    </div>
</div>

{{-- Modal crea/modifica quota --}}
@can('create', \App\Models\Quota::class)
<div class="modal fade" id="quotaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('quote.store') }}" id="quotaForm" class="modal-content">
            @csrf
            <input type="hidden" name="_method" value="POST" id="quotaFormMethod">
            <div class="modal-header">
                <h5 class="modal-title" id="quotaModalTitle">Nuova Quota</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 position-relative">
                    <label for="socioSearch" class="form-label">Socio *</label>
                    <input type="text" class="form-control" id="socioSearch" autocomplete="off" placeholder="Cerca socio per nome o cognome...">
                    <input type="hidden" name="socio_id" id="socioId">
                    <div class="list-group position-absolute w-100 shadow" id="socioResults" style="z-index: 1056; display: none;"></div>
                    <div class="invalid-feedback">Seleziona un socio dall'elenco.</div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label for="quotaAnno" class="form-label">Anno *</label>
                        <input type="number" class="form-control" name="anno" id="quotaAnno" value="{{ now()->year }}" min="2000" max="2100" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label for="quotaImporto" class="form-label">Importo (&euro;) *</label>
                        <input type="number" class="form-control" name="importo" id="quotaImporto" step="0.01" min="0.01" value="50.00" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="quotaScadenza" class="form-label">Data Scadenza *</label>
                    <input type="date" class="form-control" name="data_scadenza" id="quotaScadenza" required>
                </div>
                <div class="mb-3">
                    <label for="quotaTipo" class="form-label">Tipo</label>
                    <input type="text" class="form-control" name="tipo" id="quotaTipo" value="Quota Associativa" maxlength="100" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">Salva</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- Modal registra pagamento --}}
<div class="modal fade" id="pagamentoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="" id="pagamentoForm" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Registra Pagamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong>Socio:</strong> <span id="pagamentoSocio"></span></p>
                <p><strong>Importo:</strong> &euro; <span id="pagamentoImporto"></span></p>
                <div class="mb-3">
                    <label for="paymentMethod" class="form-label">Metodo di pagamento *</label>
                    <select class="form-select" name="payment_method" id="paymentMethod" required>
                        <option value="contanti">Contanti</option>
                        <option value="bonifico">Bonifico</option>
                        <option value="carta">Carta</option>
                        <option value="altro">Altro</option>
                        @if ($hasStripe)<option value="stripe">Genera link Stripe</option>@endif
                        @if ($hasPayPal)<option value="paypal">Genera link PayPal</option>@endif
                    </select>
                </div>
                <div class="mb-3" id="paymentDateWrapper">
                    <label for="paymentDate" class="form-label">Data pagamento *</label>
                    <input type="date" class="form-control" name="payment_date" id="paymentDate" value="{{ now()->toDateString() }}">
                </div>
                <div class="alert alert-info d-none" id="onlineNote">
                    <i class="bi bi-info-circle me-1"></i>Verrà generato un link di pagamento online da inviare al socio.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">Conferma</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Copia link pagamento ---
    const copyBtn = document.getElementById('copyPaymentLink');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            const input = document.getElementById('paymentLinkInput');
            input.select();
            navigator.clipboard.writeText(input.value).then(() => {
                copyBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiato';
            });
        });
    }

    // --- Autocomplete socio (debounce 300ms) ---
    const searchInput = document.getElementById('socioSearch');
    const socioId = document.getElementById('socioId');
    const results = document.getElementById('socioResults');
    let debounceTimer = null;

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            socioId.value = '';
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 1) { results.style.display = 'none'; return; }
            debounceTimer = setTimeout(function () {
                fetch('{{ route('quote.soci-search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(items => {
                        results.innerHTML = '';
                        items.forEach(function (item) {
                            const a = document.createElement('button');
                            a.type = 'button';
                            a.className = 'list-group-item list-group-item-action';
                            a.textContent = item.nome_completo;
                            a.addEventListener('click', function () {
                                socioId.value = item.id;
                                searchInput.value = item.nome_completo;
                                searchInput.classList.remove('is-invalid');
                                results.style.display = 'none';
                            });
                            results.appendChild(a);
                        });
                        results.style.display = items.length ? 'block' : 'none';
                    });
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!results.contains(e.target) && e.target !== searchInput) {
                results.style.display = 'none';
            }
        });
    }

    // --- Validazione client: blocca submit senza socio selezionato ---
    const quotaForm = document.getElementById('quotaForm');
    if (quotaForm) {
        quotaForm.addEventListener('submit', function (e) {
            if (!socioId.value) {
                e.preventDefault();
                searchInput.classList.add('is-invalid');
            }
        });
    }

    // --- Modal modifica quota (precompila dal data-* della riga) ---
    document.querySelectorAll('[data-quota-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(this.dataset.quotaEdit);
            document.getElementById('quotaModalTitle').textContent = 'Modifica Quota';
            quotaForm.action = d.update_url;
            document.getElementById('quotaFormMethod').value = 'PUT';
            socioId.value = d.socio_id;
            searchInput.value = d.socio_nome;
            document.getElementById('quotaAnno').value = d.anno;
            document.getElementById('quotaImporto').value = d.importo;
            document.getElementById('quotaScadenza').value = d.data_scadenza;
            document.getElementById('quotaTipo').value = d.tipo;
        });
    });

    const btnNuova = document.getElementById('btnNuovaQuota');
    if (btnNuova) {
        btnNuova.addEventListener('click', function () {
            document.getElementById('quotaModalTitle').textContent = 'Nuova Quota';
            quotaForm.action = '{{ route('quote.store') }}';
            quotaForm.reset();
            document.getElementById('quotaFormMethod').value = 'POST';
            socioId.value = '';
        });
    }

    // --- Modal pagamento ---
    document.querySelectorAll('[data-quota-pay]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(this.dataset.quotaPay);
            document.getElementById('pagamentoForm').action = d.pay_url;
            document.getElementById('pagamentoSocio').textContent = d.socio_nome;
            document.getElementById('pagamentoImporto').textContent = d.importo;
        });
    });

    // --- Toggle campo data per metodi online ---
    const methodSelect = document.getElementById('paymentMethod');
    if (methodSelect) {
        methodSelect.addEventListener('change', function () {
            const online = this.value === 'stripe' || this.value === 'paypal';
            document.getElementById('paymentDateWrapper').classList.toggle('d-none', online);
            document.getElementById('onlineNote').classList.toggle('d-none', !online);
        });
    }
});
</script>
@endpush
