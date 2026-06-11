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
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-credit-card me-2"></i>Quote Associative</h1>
    <div class="btn-toolbar gap-2">
        @can('gestisciImpostazioni', \App\Models\Quota::class)
            <a href="{{ route('quote.impostazioni.edit') }}" class="btn btn-outline-secondary">
                <i class="bi bi-gear me-1"></i>Impostazioni Pagamenti
            </a>
        @endcan
        @can('create', \App\Models\Quota::class)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quotaModal" id="btnNuovaQuota">
                <i class="bi bi-plus-lg me-1"></i>Nuova Quota
            </button>
        @endcan
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

{{-- Filtri --}}
<form method="GET" action="{{ route('quote.index') }}" class="row g-2 mb-3">
    <div class="col-auto">
        <label for="filterYear" class="form-label mb-0 small text-muted">Anno</label>
        <select name="year" id="filterYear" class="form-select" onchange="this.form.submit()">
            <option value="Tutti">Tutti</option>
            @foreach ($anni as $anno)
                <option value="{{ $anno }}" @selected((string) $filtroAnno === (string) $anno)>{{ $anno }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <label for="filterStatus" class="form-label mb-0 small text-muted">Stato</label>
        <select name="status" id="filterStatus" class="form-select" onchange="this.form.submit()">
            @foreach (['Tutti', \App\Models\Quota::STATO_PAGATA, \App\Models\Quota::STATO_DA_PAGARE, \App\Models\Quota::STATO_IN_SCADENZA, \App\Models\Quota::STATO_SCADUTA] as $st)
                <option value="{{ $st }}" @selected($filtroStato === $st)>{{ $st }}</option>
            @endforeach
        </select>
    </div>
</form>

{{-- Tabella desktop --}}
<div class="table-responsive d-none d-md-block">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Socio</th>
                <th>Tipo</th>
                <th>Anno</th>
                <th class="text-end">Importo</th>
                <th>Scadenza</th>
                <th>Stato</th>
                <th class="text-end">Azioni</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($quote as $quota)
                @php $stato = $quota->statoCalcolato(); @endphp
                <tr>
                    <td>{{ $quota->socio ? trim($quota->socio->cognome.' '.$quota->socio->nome) : '—' }}</td>
                    <td>{{ $quota->tipo }}</td>
                    <td>{{ $quota->anno }}</td>
                    <td class="text-end">&euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}</td>
                    <td>{{ $quota->data_scadenza?->format('d/m/Y') }}</td>
                    <td><span class="badge bg-{{ $badgeClass[$stato] ?? 'secondary' }}">{{ $stato }}</span></td>
                    <td class="text-end">
                        @include('quote.partials.azioni', ['quota' => $quota, 'stato' => $stato, 'hasOnlineGateway' => $hasOnlineGateway])
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Nessuna quota trovata.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Card mobile --}}
<div class="d-md-none">
    @forelse ($quote as $quota)
        @php $stato = $quota->statoCalcolato(); @endphp
        <div class="card mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold">{{ $quota->socio ? trim($quota->socio->cognome.' '.$quota->socio->nome) : '—' }}</div>
                        <div class="small text-muted">{{ $quota->tipo }} — {{ $quota->anno }}</div>
                    </div>
                    <span class="badge bg-{{ $badgeClass[$stato] ?? 'secondary' }}">{{ $stato }}</span>
                </div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-semibold">&euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}</span>
                        <span class="small text-muted ms-2">scad. {{ $quota->data_scadenza?->format('d/m/Y') }}</span>
                    </div>
                    <div>
                        @include('quote.partials.azioni', ['quota' => $quota, 'stato' => $stato, 'hasOnlineGateway' => $hasOnlineGateway])
                    </div>
                </div>
            </div>
        </div>
    @empty
        <p class="text-center text-muted py-4">Nessuna quota trovata.</p>
    @endforelse
</div>

{{ $quote->links() }}

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
