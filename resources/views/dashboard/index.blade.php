@extends('layouts.app')

@section('title', 'Dashboard - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-end pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 mb-1">Dashboard</h1>
            <p class="text-muted mb-0">
                @if ($vistaGlobale)
                    Panoramica globale di tutte le associazioni
                @else
                    Panoramica di {{ session('associazione_nome', 'Manager') }}
                @endif
            </p>
        </div>
        <div class="text-muted small text-nowrap pb-1">
            Aggiornata alle {{ now()->format('H:i') }} del {{ now()->format('d/m/Y') }}
        </div>
    </div>

    @if ($utente->isSuperAdmin())
        <div class="panel mb-4">
            <div class="panel-header">
                <span>Associazioni</span>
                <form method="POST" action="{{ route('tenant.switch') }}" class="m-0">
                    @csrf
                    <input type="hidden" name="associazione_id" value="">
                    <button type="submit" class="btn btn-outline-secondary btn-sm {{ session('associazione_id') ? '' : 'active' }}">
                        <i class="bi bi-globe2 me-1"></i>Vista globale
                    </button>
                </form>
            </div>
            <ul class="panel-list">
                @forelse ($associazioni as $associazione)
                    @php $attiva = session('associazione_id') === $associazione->id; @endphp
                    <li class="assoc-row {{ $attiva ? 'active' : '' }}">
                        <span class="assoc-name">
                            <i class="bi bi-building"></i>{{ $associazione->nome }}
                            @if ($attiva)<span class="badge bg-primary">Attiva</span>@endif
                        </span>
                        @unless ($attiva)
                            <form method="POST" action="{{ route('tenant.switch') }}" class="m-0">
                                @csrf
                                <input type="hidden" name="associazione_id" value="{{ $associazione->id }}">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Entra</button>
                            </form>
                        @endunless
                    </li>
                @empty
                    <li class="panel-empty">
                        <i class="bi bi-buildings"></i>
                        Nessuna associazione attiva. Creane una da Amministrazione &rsaquo; Associazioni.
                    </li>
                @endforelse
            </ul>
        </div>
    @endif

    <div class="metrics-strip mb-4">
        <div class="metric-cell">
            <div class="metric-label">Totale soci</div>
            <div class="metric-value">{{ $stats['total_soci'] }}</div>
        </div>
        <div class="metric-cell">
            <div class="metric-label">Soci attivi</div>
            <div class="metric-value">{{ $stats['soci_attivi'] }}</div>
        </div>
        <div class="metric-cell {{ $stats['scadenze_imminenti'] > 0 ? 'metric-warn' : '' }}">
            <div class="metric-label">Scadenze 30 giorni</div>
            <div class="metric-value">{{ $stats['scadenze_imminenti'] }}</div>
        </div>
        <div class="metric-cell">
            <div class="metric-label">Eventi questo mese</div>
            <div class="metric-value">{{ $stats['eventi_mese'] }}</div>
        </div>
        <div class="metric-cell">
            <div class="metric-label">Quote pagate</div>
            <div class="metric-value">{{ $stats['quote_pagate'] }}<span class="metric-hint">{{ now()->year }}</span></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="panel h-100">
                <div class="panel-header"><span>Prossimi eventi</span></div>
                <ul class="panel-list">
                    @forelse ($prossimiEventi as $evento)
                        <li>
                            <span>{{ $evento->titolo }}</span>
                            <span class="text-muted text-nowrap">{{ $evento->data_evento?->format('d/m/Y') }}</span>
                        </li>
                    @empty
                        <li class="panel-empty">
                            <i class="bi bi-calendar-event"></i>
                            Nessun evento in programma. Creane uno da Eventi.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
