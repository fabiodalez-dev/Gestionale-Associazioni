@extends('pubblico.layouts.app')

@section('title', 'Calendario eventi — '.$associazione->nome)

@section('content')
    @php
        $nomiMesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
        $oggi = now()->format('Y-m-d');
    @endphp

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('pubblico.home.show', $associazione->slug) }}">Home</a></li>
            <li class="breadcrumb-item"><a href="{{ route('pubblico.eventi.index', $associazione->slug) }}">Eventi</a></li>
            <li class="breadcrumb-item active" aria-current="page">Calendario</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between flex-wrap align-items-center mb-3 gap-2">
        <h1 class="h3 mb-0"><i class="bi bi-calendar3 me-2"></i>{{ $nomiMesi[(int) $mese->format('n')] }} {{ $mese->format('Y') }}</h1>
        <a href="{{ route('pubblico.calendario.ics', $associazione->slug) }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-calendar-plus me-1"></i>Aggiungi al tuo calendario (.ics)
        </a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="{{ route('pubblico.calendario', ['associazione' => $associazione->slug, 'mese' => $mesePrecedente]) }}"
           class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i> Precedente</a>
        <a href="{{ route('pubblico.calendario', $associazione->slug) }}" class="btn btn-outline-primary btn-sm">Oggi</a>
        <a href="{{ route('pubblico.calendario', ['associazione' => $associazione->slug, 'mese' => $meseSuccessivo]) }}"
           class="btn btn-outline-secondary btn-sm">Successivo <i class="bi bi-chevron-right"></i></a>
    </div>

    <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg); overflow: hidden;">
        <div class="table-responsive">
            <table class="table table-bordered align-top mb-0" style="table-layout: fixed; min-width: 700px;">
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
                                <td class="{{ $delMese ? '' : 'bg-body-tertiary text-muted' }}" style="height: 96px;">
                                    <div class="small fw-semibold mb-1 {{ $chiave === $oggi ? 'text-primary' : '' }}">{{ $giorno->day }}</div>
                                    @foreach ($eventiGiorno as $evento)
                                        <a href="{{ route('pubblico.eventi.show', ['associazione' => $associazione->slug, 'evento' => $evento->id]) }}"
                                           class="d-block text-decoration-none text-truncate small mb-1 px-1 rounded bg-success-subtle"
                                           title="{{ $evento->titolo }} — {{ $evento->data_evento->format('H:i') }}">
                                            {{ $evento->data_evento->format('H:i') }}
                                            @if ($evento->solo_soci)
                                                <i class="bi bi-person-badge" title="Riservato ai soci"></i>
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

    <p class="text-muted small mt-2 mb-0">
        <i class="bi bi-person-badge me-1"></i>= evento riservato ai soci.
        Il feed <a href="{{ route('pubblico.calendario.ics', $associazione->slug) }}">calendario.ics</a> si aggiorna automaticamente nel tuo calendario.
    </p>
@endsection
