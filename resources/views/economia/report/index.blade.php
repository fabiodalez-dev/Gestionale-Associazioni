@extends('layouts.app')

@section('title', 'Report economico')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-graph-up me-2"></i>Report economico</h1>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('prima-nota.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-journal-text me-1"></i>Prima nota
        </a>
        <a href="{{ route('report-economico.export', ['dal' => $dal->toDateString(), 'al' => $al->toDateString()]) }}" class="btn btn-primary">
            <i class="bi bi-file-earmark-excel me-1"></i>Esporta Excel
        </a>
    </div>
</div>

{{-- Periodo --}}
<form method="GET" action="{{ route('report-economico.index') }}" class="row g-2 mb-4">
    <div class="col-auto">
        <label for="filtroDal" class="form-label mb-0 small text-muted">Dal</label>
        <input type="date" name="dal" id="filtroDal" class="form-control" value="{{ $dal->toDateString() }}">
    </div>
    <div class="col-auto">
        <label for="filtroAl" class="form-label mb-0 small text-muted">Al</label>
        <input type="date" name="al" id="filtroAl" class="form-control" value="{{ $al->toDateString() }}">
    </div>
    <div class="col-auto align-self-end">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Applica</button>
    </div>
</form>

{{-- Totali --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Entrate</div>
            <div class="fs-5 fw-semibold text-success">&euro; {{ number_format($totaleEntrate, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Uscite</div>
            <div class="fs-5 fw-semibold text-danger">&euro; {{ number_format($totaleUscite, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Saldo del periodo</div>
            <div class="fs-5 fw-semibold {{ $saldo >= 0 ? 'text-success' : 'text-danger' }}">&euro; {{ number_format($saldo, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Movimenti</div>
            <div class="fs-5 fw-semibold">{{ $numeroMovimenti }}</div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    {{-- Andamento mensile --}}
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart me-1"></i>Andamento mensile</div>
            <div class="card-body">
                <canvas id="chartMensile" height="220"
                        data-labels='@json(array_keys($mensile))'
                        data-entrate='@json(array_column($mensile, 'entrate'))'
                        data-uscite='@json(array_column($mensile, 'uscite'))'></canvas>
            </div>
        </div>
    </div>

    {{-- Totali per categoria --}}
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-tags me-1"></i>Totali per categoria</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>Categoria</th><th>Tipo</th><th class="text-end">N.</th><th class="text-end">Totale</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($perCategoria as $riga)
                                <tr>
                                    <td>{{ $riga['categoria'] }}</td>
                                    <td>
                                        <span class="badge bg-{{ $riga['tipo'] === \App\Models\Movimento::TIPO_ENTRATA ? 'success' : 'danger' }}">
                                            {{ ucfirst($riga['tipo']) }}
                                        </span>
                                    </td>
                                    <td class="text-end">{{ $riga['numero'] }}</td>
                                    <td class="text-end">&euro; {{ number_format($riga['totale'], 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">Nessun movimento nel periodo.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
(function () {
    var canvas = document.getElementById('chartMensile');
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [
                {
                    label: 'Entrate',
                    data: JSON.parse(canvas.dataset.entrate),
                    backgroundColor: '#22A06B'
                },
                {
                    label: 'Uscite',
                    data: JSON.parse(canvas.dataset.uscite),
                    backgroundColor: '#E5484D'
                }
            ]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } }
        }
    });
})();
</script>
@endpush
