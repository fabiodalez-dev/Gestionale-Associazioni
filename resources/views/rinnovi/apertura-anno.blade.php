@extends('layouts.app')

@section('title', 'Apri anno sociale '.$anno.' - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-1">Apri anno sociale {{ $anno }}</h1>
            <p class="text-muted mb-0">Genera in blocco le quote {{ $anno }} per i soci attivi (i soci con quota già presente vengono saltati).</p>
        </div>
        <form method="GET" action="{{ route('apertura-anno.index') }}" class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0" for="selettoreAnno">Anno</label>
            <select class="form-select" id="selettoreAnno" name="anno" onchange="this.form.submit()" style="width: auto;">
                @foreach ([$annoCorrente, $annoProssimo] as $a)
                    <option value="{{ $a }}" @selected($a === $anno)>{{ $a }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- Anteprima --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Soci attivi</div>
                    <div class="fs-3 fw-semibold" data-testid="anteprima-soci-attivi">{{ $anteprima['soci_attivi'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Quote {{ $anno }} già presenti</div>
                    <div class="fs-3 fw-semibold text-muted" data-testid="anteprima-gia-aperte">{{ $anteprima['gia_aperte'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Quote da generare</div>
                    <div class="fs-3 fw-semibold text-primary" data-testid="anteprima-da-generare">{{ $anteprima['da_generare'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Importo totale atteso</div>
                    <div class="fs-3 fw-semibold text-success" data-testid="anteprima-importo">&euro; {{ number_format($anteprima['importo_totale'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Dettaglio per tipo socio</div>
        @if (empty($anteprima['per_tipo']))
            <div class="card-body text-center text-muted py-4">
                Nessuna quota da generare per l'anno {{ $anno }}.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tipo socio</th>
                            <th class="text-end">Soci</th>
                            <th class="text-end">Quota unitaria</th>
                            <th class="text-end">Totale</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($anteprima['per_tipo'] as $riga)
                            <tr>
                                <td>{{ $riga['tipo'] }}</td>
                                <td class="text-end">{{ $riga['soci'] }}</td>
                                <td class="text-end">&euro; {{ number_format($riga['importo_unitario'], 2, ',', '.') }}</td>
                                <td class="text-end">&euro; {{ number_format($riga['importo_totale'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('apertura-anno.genera') }}"
          onsubmit="return confirm('Generare {{ $anteprima['da_generare'] }} quote per l\'anno {{ $anno }}?');">
        @csrf
        <input type="hidden" name="anno" value="{{ $anno }}">
        <button type="submit" class="btn btn-primary" @disabled($anteprima['da_generare'] === 0)>
            <i class="bi bi-calendar-plus me-1"></i>Apri anno {{ $anno }}: genera {{ $anteprima['da_generare'] }} quote
        </button>
        <span class="text-muted small ms-2">Operazione idempotente: può essere ripetuta senza creare duplicati.</span>
    </form>
@endsection
