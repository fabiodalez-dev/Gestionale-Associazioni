@extends('layouts.app')

@section('title', 'Libro soci')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-book me-2"></i>Libro soci</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="{{ route('libro-soci.pdf', array_filter(['anno' => $anno])) }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-pdf me-1"></i>Esporta PDF
        </a>
        <a href="{{ route('libro-soci.excel', array_filter(['anno' => $anno])) }}" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Esporta Excel
        </a>
    </div>
</div>

<p class="text-muted">
    Registro cronologico ufficiale dei soci ammessi, con numero progressivo di iscrizione per anno,
    delibera di ammissione e data/motivo di cessazione. Vista di sola lettura generata dai dati correnti.
</p>

<form method="GET" action="{{ route('libro-soci.index') }}" class="row g-2 mb-3">
    <div class="col-md-3">
        <select class="form-select" name="anno" onchange="this.form.submit()">
            <option value="">Tutti gli anni</option>
            @foreach ($anni as $a)
                <option value="{{ $a }}" @selected($anno === $a)>{{ $a }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-outline-primary">Filtra</button>
    </div>
</form>

@forelse ($perAnno as $annoGruppo => $righeAnno)
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between">
            <span><strong>Anno {{ $annoGruppo }}</strong></span>
            <span>{{ count($righeAnno) }} {{ count($righeAnno) === 1 ? 'iscrizione' : 'iscrizioni' }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>N.</th>
                        <th>Socio</th>
                        <th>Codice Fiscale</th>
                        <th>Data di nascita</th>
                        <th>Ammissione</th>
                        <th>Delibera</th>
                        <th>Stato</th>
                        <th>Cessazione</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($righeAnno as $riga)
                        @php
                            /** @var \App\Models\Socio $socio */
                            $socio = $riga['socio'];
                            /** @var \App\Models\Cessazione|null $cessazione */
                            $cessazione = $riga['cessazione'];
                        @endphp
                        <tr>
                            <td><strong>{{ $riga['progressivo'] }}/{{ $riga['anno'] }}</strong></td>
                            <td>
                                <a href="{{ route('soci.show', $socio) }}">{{ $socio->cognome }} {{ $socio->nome }}</a>
                                <small class="text-muted d-block">Socio n. {{ $socio->numero_socio }}</small>
                            </td>
                            <td>{{ $socio->codice_fiscale ?: '—' }}</td>
                            <td>{{ $socio->data_nascita?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $socio->data_iscrizione?->format('d/m/Y') }}</td>
                            <td>
                                @if (filled($riga['delibera_numero']))
                                    n. {{ $riga['delibera_numero'] }}
                                    @if ($riga['delibera_data']) <small class="text-muted">del {{ $riga['delibera_data']->format('d/m/Y') }}</small> @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $socio->stato === \App\Models\Socio::STATO_ATTIVO ? 'success' : ($cessazione !== null ? 'danger' : 'secondary') }}">{{ $socio->stato }}</span>
                            </td>
                            <td>
                                @if ($cessazione !== null)
                                    {{ $cessazione->data?->format('d/m/Y') }}
                                    <small class="text-muted d-block">{{ ucfirst($cessazione->tipo) }}@if (filled($cessazione->motivo)) — {{ \Illuminate\Support\Str::limit($cessazione->motivo, 60) }}@endif</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-book fs-3 d-block mb-2"></i>
            Il libro soci è vuoto: nessun socio ammesso{{ $anno !== null ? ' nell\'anno '.$anno : '' }}.
        </div>
    </div>
@endforelse
@endsection
