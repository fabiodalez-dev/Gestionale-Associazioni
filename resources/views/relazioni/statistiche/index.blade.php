@extends('layouts.app')

@section('title', 'Statistiche annuali - '.session('app_name', config('app.name')))

@php
    $metriche = [
        'nuovi_soci' => ['Nuovi soci', 'bi-person-plus', false],
        'cessazioni' => ['Cessazioni', 'bi-person-dash', true],
        'retention' => ['Retention %', 'bi-arrow-repeat', false],
        'tessere' => ['Tessere emesse', 'bi-credit-card', false],
        'partecipazioni' => ['Partecipazioni eventi', 'bi-people', false],
        'ore_volontariato' => ['Ore volontariato', 'bi-clock-history', false],
        'entrate' => ['Entrate (€)', 'bi-graph-up-arrow', false],
        'uscite' => ['Uscite (€)', 'bi-graph-down-arrow', true],
        'saldo' => ['Saldo (€)', 'bi-cash-stack', false],
        'donazioni' => ['Donazioni (€)', 'bi-heart', false],
    ];
    $fmt = fn ($k, $v) => in_array($k, ['entrate', 'uscite', 'saldo', 'donazioni'], true)
        ? '€ '.number_format((float) $v, 2, ',', '.')
        : (in_array($k, ['retention', 'ore_volontariato'], true) ? number_format((float) $v, ($k === 'retention' ? 1 : 2), ',', '.') : (int) $v);
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-graph-up-arrow me-2"></i>Statistiche annuali</h1>
            <p class="text-muted mb-0">"Anno in cifre": {{ $anno }} a confronto con {{ $dati['anno_precedente'] }}.</p>
        </div>
        <div class="d-flex gap-2 align-items-end">
            <form method="GET" action="{{ route('statistiche-annuali.index') }}">
                <label class="form-label small mb-1" for="anno">Anno</label>
                <select class="form-select form-select-sm" id="anno" name="anno" onchange="this.form.submit()">
                    @foreach ($anniDisponibili as $a)
                        <option value="{{ $a }}" @selected($a === $anno)>{{ $a }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('statistiche-annuali.pdf', ['anno' => $anno]) }}" class="btn btn-outline-danger">
                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
            </a>
        </div>
    </div>

    {{-- Schede metriche con variazione --}}
    <div class="row g-3 mb-4">
        @foreach ($metriche as $chiave => [$label, $icona, $negativoMeglio])
            @php
                $valore = $dati['corrente'][$chiave];
                $prec = $dati['precedente'][$chiave];
                $var = $dati['variazioni'][$chiave];
                $colore = 'text-muted';
                if ($var !== null && abs($var) > 0.0001) {
                    $positivo = $var > 0;
                    $buono = $negativoMeglio ? !$positivo : $positivo;
                    $colore = $buono ? 'text-success' : 'text-danger';
                }
            @endphp
            <div class="col-6 col-md-4 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="text-muted small"><i class="bi {{ $icona }} me-1"></i>{{ $label }}</span>
                        </div>
                        <div class="h4 mb-0 mt-1">{{ $fmt($chiave, $valore) }}</div>
                        <div class="small {{ $colore }}">
                            @if ($var === null)
                                <span class="text-muted">{{ $dati['anno_precedente'] }}: {{ $fmt($chiave, $prec) }}</span>
                            @else
                                <i class="bi {{ $var >= 0 ? 'bi-arrow-up' : 'bi-arrow-down' }}"></i>
                                {{ number_format(abs($var), 1, ',', '.') }}% vs {{ $dati['anno_precedente'] }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Grafico di confronto --}}
    @php
        $chiaviGrafico = ['nuovi_soci', 'cessazioni', 'tessere', 'partecipazioni', 'ore_volontariato', 'donazioni'];
        $etichetteGrafico = ['Nuovi soci', 'Cessazioni', 'Tessere', 'Partecipazioni', 'Ore vol.', 'Donazioni'];
        $datiPrecedenti = array_map(fn ($k) => $dati['precedente'][$k], $chiaviGrafico);
        $datiCorrenti = array_map(fn ($k) => $dati['corrente'][$k], $chiaviGrafico);
    @endphp
    <div class="card">
        <div class="card-header"><i class="bi bi-bar-chart me-1"></i>Confronto {{ $dati['anno_precedente'] }} vs {{ $anno }}</div>
        <div class="card-body">
            <canvas id="chartConfronto" height="120"
                    data-labels='@json($etichetteGrafico)'
                    data-precedente='@json($datiPrecedenti)'
                    data-corrente='@json($datiCorrenti)'
                    data-anno-corrente="{{ $anno }}"
                    data-anno-precedente="{{ $dati['anno_precedente'] }}"></canvas>
        </div>
    </div>
@endsection

@push('scripts')
<script src="{{ asset('assets/vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
(function () {
    var canvas = document.getElementById('chartConfronto');
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [
                {
                    label: canvas.dataset.annoPrecedente,
                    data: JSON.parse(canvas.dataset.precedente),
                    backgroundColor: 'rgba(108, 117, 125, .6)'
                },
                {
                    label: canvas.dataset.annoCorrente,
                    data: JSON.parse(canvas.dataset.corrente),
                    backgroundColor: 'rgba(255, 123, 17, .8)'
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
