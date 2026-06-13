@extends('layouts.app')

@section('title', 'Prima nota')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-journal-text me-2"></i>Prima nota</h1>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('report-economico.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-graph-up me-1"></i>Report economico
        </a>
        @can('create', \App\Models\Movimento::class)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#movimentoModal" id="btnNuovoMovimento">
                <i class="bi bi-plus-lg me-1"></i>Nuovo movimento
            </button>
        @endcan
    </div>
</div>

{{-- Riepilogo --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-4">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Entrate (filtri attivi)</div>
            <div class="fs-5 fw-semibold text-success">&euro; {{ number_format($totaleEntrate, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Uscite (filtri attivi)</div>
            <div class="fs-5 fw-semibold text-danger">&euro; {{ number_format($totaleUscite, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Saldo</div>
            <div class="fs-5 fw-semibold {{ $saldoTotale >= 0 ? 'text-success' : 'text-danger' }}">
                &euro; {{ number_format($saldoTotale, 2, ',', '.') }}
            </div>
        </div></div>
    </div>
</div>

{{-- Filtri --}}
<form method="GET" action="{{ route('prima-nota.index') }}" class="row g-2 mb-3">
    <div class="col-auto">
        <label for="filtroDal" class="form-label mb-0 small text-muted">Dal</label>
        <input type="date" name="dal" id="filtroDal" class="form-control" value="{{ $filtri['dal'] }}">
    </div>
    <div class="col-auto">
        <label for="filtroAl" class="form-label mb-0 small text-muted">Al</label>
        <input type="date" name="al" id="filtroAl" class="form-control" value="{{ $filtri['al'] }}">
    </div>
    <div class="col-auto">
        <label for="filtroTipo" class="form-label mb-0 small text-muted">Tipo</label>
        <select name="tipo" id="filtroTipo" class="form-select">
            <option value="">Tutti</option>
            @foreach (\App\Models\Movimento::TIPI as $tipo)
                <option value="{{ $tipo }}" @selected($filtri['tipo'] === $tipo)>{{ ucfirst($tipo) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <label for="filtroCategoria" class="form-label mb-0 small text-muted">Categoria</label>
        <select name="categoria_id" id="filtroCategoria" class="form-select">
            <option value="">Tutte</option>
            @foreach ($categorie as $categoria)
                <option value="{{ $categoria->id }}" @selected($filtri['categoria_id'] === $categoria->id)>
                    {{ $categoria->nome }} ({{ $categoria->tipo }})
                </option>
            @endforeach
        </select>
    </div>
    @if (isset($progetti) && $progetti->isNotEmpty())
        <div class="col-auto">
            <label for="filtroProgetto" class="form-label mb-0 small text-muted">Progetto</label>
            <select name="progetto_id" id="filtroProgetto" class="form-select">
                <option value="">Tutti</option>
                @foreach ($progetti as $progetto)
                    <option value="{{ $progetto->id }}" @selected(($filtri['progetto_id'] ?? null) === $progetto->id)>
                        {{ $progetto->nome }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="col-auto align-self-end">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
        <a href="{{ route('prima-nota.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Data</th>
                <th>Categoria</th>
                <th>Descrizione</th>
                <th>Metodo</th>
                <th class="text-end">Entrata</th>
                <th class="text-end">Uscita</th>
                <th class="text-end">Saldo progressivo</th>
                <th class="text-end">Azioni</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($movimenti as $movimento)
                <tr>
                    <td>{{ $movimento->data?->format('d/m/Y') }}</td>
                    <td>{{ $movimento->categoria->nome ?? '—' }}</td>
                    <td>
                        {{ $movimento->descrizione ?: '—' }}
                        @if ($movimento->origine_id !== null)
                            <span class="badge bg-secondary ms-1" title="Generato automaticamente dal pagamento">auto</span>
                        @endif
                        @if ($movimento->progetto)
                            <span class="badge bg-info-subtle text-info-emphasis ms-1" title="Progetto"><i class="bi bi-kanban"></i> {{ $movimento->progetto->nome }}</span>
                        @endif
                    </td>
                    <td>{{ $movimento->metodo ?: '—' }}</td>
                    <td class="text-end text-success">
                        {{ $movimento->tipo === \App\Models\Movimento::TIPO_ENTRATA ? '€ '.number_format((float) $movimento->importo, 2, ',', '.') : '' }}
                    </td>
                    <td class="text-end text-danger">
                        {{ $movimento->tipo === \App\Models\Movimento::TIPO_USCITA ? '€ '.number_format((float) $movimento->importo, 2, ',', '.') : '' }}
                    </td>
                    <td class="text-end fw-semibold {{ ($saldi[$movimento->id] ?? 0) >= 0 ? '' : 'text-danger' }}">
                        &euro; {{ number_format($saldi[$movimento->id] ?? 0, 2, ',', '.') }}
                    </td>
                    <td class="text-end">
                        @if ($movimento->origine_id === null)
                            @can('update', $movimento)
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary btn-edit-movimento"
                                        data-update-url="{{ route('prima-nota.update', $movimento) }}"
                                        data-tipo="{{ $movimento->tipo }}"
                                        data-importo="{{ $movimento->importo }}"
                                        data-data="{{ $movimento->data?->format('Y-m-d') }}"
                                        data-categoria="{{ $movimento->categoria_id }}"
                                        data-descrizione="{{ $movimento->descrizione }}"
                                        data-metodo="{{ $movimento->metodo }}"
                                        data-progetto="{{ $movimento->progetto_id }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            @endcan
                            @can('delete', $movimento)
                                <form method="POST" action="{{ route('prima-nota.destroy', $movimento) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questo movimento?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">Nessun movimento registrato.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $movimenti->links() }}

{{-- Modale crea/modifica movimento --}}
@can('create', \App\Models\Movimento::class)
<div class="modal fade" id="movimentoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('prima-nota.store') }}" class="modal-content" id="movimentoForm">
            @csrf
            <input type="hidden" name="_method" value="POST" id="movimentoMethod">
            <div class="modal-header">
                <h5 class="modal-title" id="movimentoModalTitle">Nuovo movimento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $errore)
                                <li>{{ $errore }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="mb-3">
                    <label for="campoTipo" class="form-label">Tipo *</label>
                    <select name="tipo" id="campoTipo" class="form-select" required>
                        @foreach (\App\Models\Movimento::TIPI as $tipo)
                            <option value="{{ $tipo }}" @selected(old('tipo') === $tipo)>{{ ucfirst($tipo) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label for="campoCategoria" class="form-label">Categoria *</label>
                    <select name="categoria_id" id="campoCategoria" class="form-select" required>
                        @foreach ($categorie as $categoria)
                            <option value="{{ $categoria->id }}" data-tipo="{{ $categoria->tipo }}" @selected(old('categoria_id') === $categoria->id)>
                                {{ $categoria->nome }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-6 mb-3">
                        <label for="campoImporto" class="form-label">Importo (&euro;) *</label>
                        <input type="number" step="0.01" min="0.01" name="importo" id="campoImporto" class="form-control" value="{{ old('importo') }}" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label for="campoData" class="form-label">Data *</label>
                        <input type="date" name="data" id="campoData" class="form-control" value="{{ old('data', now()->toDateString()) }}" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="campoDescrizione" class="form-label">Descrizione</label>
                    <input type="text" name="descrizione" id="campoDescrizione" class="form-control" maxlength="500" value="{{ old('descrizione') }}">
                </div>
                <div class="mb-3">
                    <label for="campoMetodo" class="form-label">Metodo</label>
                    <input type="text" name="metodo" id="campoMetodo" class="form-control" maxlength="50"
                           placeholder="contanti, bonifico, POS, ..." value="{{ old('metodo') }}">
                </div>
                @if (isset($progetti) && $progetti->isNotEmpty())
                    <div class="mb-3">
                        <label for="campoProgetto" class="form-label">Progetto</label>
                        <select name="progetto_id" id="campoProgetto" class="form-select">
                            <option value="">— Nessuno —</option>
                            @foreach ($progetti as $progetto)
                                <option value="{{ $progetto->id }}" @selected(old('progetto_id') === $progetto->id)>{{ $progetto->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">Salva</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection

@push('scripts')
<script>
(function () {
    var modalEl = document.getElementById('movimentoModal');
    if (!modalEl) return;

    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var form = document.getElementById('movimentoForm');
    var methodField = document.getElementById('movimentoMethod');
    var title = document.getElementById('movimentoModalTitle');
    var storeUrl = @json(route('prima-nota.store'));
    var campoTipo = document.getElementById('campoTipo');
    var campoCategoria = document.getElementById('campoCategoria');

    // Mostra solo le categorie coerenti col tipo selezionato
    function filtraCategorie() {
        var tipo = campoTipo.value;
        var primaVisibile = null;
        Array.prototype.forEach.call(campoCategoria.options, function (opt) {
            var visibile = opt.dataset.tipo === tipo;
            opt.hidden = !visibile;
            opt.disabled = !visibile;
            if (visibile && primaVisibile === null) primaVisibile = opt.value;
        });
        var selezionata = campoCategoria.selectedOptions[0];
        if (!selezionata || selezionata.disabled) campoCategoria.value = primaVisibile || '';
    }

    campoTipo.addEventListener('change', filtraCategorie);
    filtraCategorie();

    var btnNuovo = document.getElementById('btnNuovoMovimento');
    if (btnNuovo) {
        btnNuovo.addEventListener('click', function () {
            form.action = storeUrl;
            methodField.value = 'POST';
            title.textContent = 'Nuovo movimento';
            form.reset();
            filtraCategorie();
        });
    }

    document.querySelectorAll('.btn-edit-movimento').forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.action = btn.dataset.updateUrl;
            methodField.value = 'PUT';
            title.textContent = 'Modifica movimento';
            campoTipo.value = btn.dataset.tipo || 'entrata';
            filtraCategorie();
            campoCategoria.value = btn.dataset.categoria || '';
            document.getElementById('campoImporto').value = btn.dataset.importo || '';
            document.getElementById('campoData').value = btn.dataset.data || '';
            document.getElementById('campoDescrizione').value = btn.dataset.descrizione || '';
            document.getElementById('campoMetodo').value = btn.dataset.metodo || '';
            var campoProgetto = document.getElementById('campoProgetto');
            if (campoProgetto) campoProgetto.value = btn.dataset.progetto || '';
            if (modal) modal.show();
        });
    });

    @if ($errors->any())
        if (modal) modal.show();
    @endif
})();
</script>
@endpush
