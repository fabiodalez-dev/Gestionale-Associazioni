@extends('layouts.app')

@section('title', 'Calendario eventi - '.session('app_name', config('app.name')))

@section('content')
    @php
        $nomiMesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
        $oggi = now()->format('Y-m-d');
    @endphp

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-calendar3 me-2"></i>Calendario eventi</h1>
            <p class="text-muted mb-0">{{ $nomiMesi[(int) $mese->format('n')] }} {{ $mese->format('Y') }}</p>
        </div>
        <div class="d-flex gap-2">
            @if (Route::has('eventi-ricorrenti.create'))
                <a href="{{ route('eventi-ricorrenti.create') }}" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-repeat me-1"></i>Nuovo evento ricorrente
                </a>
            @endif
            <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1"></i>Elenco eventi
            </a>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="{{ route('calendario-eventi.index', ['mese' => $mesePrecedente]) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-left"></i> Mese precedente
        </a>
        <a href="{{ route('calendario-eventi.index') }}" class="btn btn-outline-primary btn-sm">Oggi</a>
        <a href="{{ route('calendario-eventi.index', ['mese' => $meseSuccessivo]) }}" class="btn btn-outline-secondary btn-sm">
            Mese successivo <i class="bi bi-chevron-right"></i>
        </a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-top mb-0" style="table-layout: fixed; min-width: 840px;">
                    <thead class="table-light">
                        <tr>
                            @foreach (['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'] as $giorno)
                                <th class="text-center small">{{ $giorno }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($settimane as $settimana)
                            <tr>
                                @foreach ($settimana as $giorno)
                                    @php
                                        $chiave = $giorno->format('Y-m-d');
                                        $delMese = $giorno->month === $mese->month;
                                        $eventiGiorno = $eventiPerGiorno->get($chiave, collect());
                                    @endphp
                                    <td class="{{ $delMese ? '' : 'bg-body-tertiary text-muted' }}" style="height: 110px;">
                                        <div class="small fw-semibold mb-1 {{ $chiave === $oggi ? 'text-primary' : '' }}">
                                            {{ $giorno->day }}
                                            @if ($chiave === $oggi)
                                                <span class="badge bg-primary ms-1">oggi</span>
                                            @endif
                                        </div>
                                        @foreach ($eventiGiorno as $evento)
                                            <a href="{{ route('eventi.index', ['edit' => $evento->id]) }}"
                                               class="d-block text-decoration-none text-truncate small mb-1 px-1 rounded
                                                      {{ $evento->stato_pubblicazione === \App\Models\Evento::STATO_PUBBLICATO ? 'bg-success-subtle' : 'bg-secondary-subtle' }}"
                                               title="{{ $evento->titolo }} — {{ $evento->data_evento->format('H:i') }}">
                                                {{ $evento->data_evento->format('H:i') }}
                                                @if ($evento->isInSerie())
                                                    <i class="bi bi-arrow-repeat"></i>
                                                @endif
                                                @if ($evento->solo_soci)
                                                    <i class="bi bi-person-badge"></i>
                                                @endif
                                                {{ $evento->titolo }}
                                            </a>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white small text-muted">
            <span class="badge bg-success-subtle text-dark me-1">Pubblicato</span>
            <span class="badge bg-secondary-subtle text-dark me-3">Bozza / archiviato</span>
            <i class="bi bi-arrow-repeat me-1"></i>Serie ricorrente
            <i class="bi bi-person-badge ms-3 me-1"></i>Riservato ai soci
        </div>
    </div>
@endsection
