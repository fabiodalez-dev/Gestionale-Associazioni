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

    {{-- Alert mandati organi sociali in scadenza (piano vita sociale, f.16) --}}
    @if (($caricheInScadenza ?? null) !== null && $caricheInScadenza->isNotEmpty())
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Organi sociali:</strong>
            {{ $caricheInScadenza->count() }} {{ $caricheInScadenza->count() === 1 ? 'mandato in scadenza' : 'mandati in scadenza' }}
            nei prossimi {{ \App\Models\Carica::GIORNI_ALERT_SCADENZA }} giorni
            <ul class="mb-1 mt-1">
                @foreach ($caricheInScadenza->take(5) as $carica)
                    <li>{{ $carica->ruolo }} — {{ $carica->socio?->nome_completo ?? 'Socio eliminato' }} (scade il {{ $carica->fine?->format('d/m/Y') }})</li>
                @endforeach
            </ul>
            <a href="{{ route('organi-sociali.index') }}" class="alert-link">Vai agli organi sociali</a>
        </div>
    @endif

    <div class="metrics-strip mb-4">
        <div class="metric-cell">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-people"></i></span>
                <span class="metric-label">Totale soci</span>
            </div>
            <div class="metric-value">{{ $stats['total_soci'] }}</div>
        </div>
        <div class="metric-cell metric-ok">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-person-check"></i></span>
                <span class="metric-label">Soci attivi</span>
            </div>
            <div class="metric-value">{{ $stats['soci_attivi'] }}</div>
        </div>
        <div class="metric-cell {{ $stats['scadenze_imminenti'] > 0 ? 'metric-warn' : '' }}">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-hourglass-split"></i></span>
                <span class="metric-label">Scadenze 30 giorni</span>
            </div>
            <div class="metric-value">{{ $stats['scadenze_imminenti'] }}</div>
        </div>
        <div class="metric-cell">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-calendar-event"></i></span>
                <span class="metric-label">Eventi questo mese</span>
            </div>
            <div class="metric-value">{{ $stats['eventi_mese'] }}</div>
        </div>
        <div class="metric-cell">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-credit-card"></i></span>
                <span class="metric-label">Quote pagate</span>
            </div>
            <div class="metric-value">{{ $stats['quote_pagate'] }}<span class="metric-hint">{{ now()->year }}</span></div>
        </div>
    </div>

    @if ($kpiPrenotazioni !== null)
        {{-- KPI prenotazioni frontend pubblico (solo se attivo per il tenant) --}}
        <h2 class="h5 mb-2"><i class="bi bi-ticket-perforated me-2"></i>Prenotazioni frontend</h2>
        <div class="metrics-strip mb-3">
            <div class="metric-cell metric-ok">
                <div class="metric-head">
                    <span class="metric-chip"><i class="bi bi-check-circle"></i></span>
                    <span class="metric-label">Confermate (30 gg)</span>
                </div>
                <div class="metric-value">{{ $kpiPrenotazioni['confermate_30gg'] }}</div>
            </div>
            <div class="metric-cell">
                <div class="metric-head">
                    <span class="metric-chip"><i class="bi bi-cash-stack"></i></span>
                    <span class="metric-label">Incasso eventi del mese</span>
                </div>
                <div class="metric-value">€ {{ number_format($kpiPrenotazioni['incasso_mese'], 2, ',', '.') }}</div>
            </div>
            <div class="metric-cell {{ $kpiPrenotazioni['in_lista_attesa'] > 0 ? 'metric-warn' : '' }}">
                <div class="metric-head">
                    <span class="metric-chip"><i class="bi bi-people"></i></span>
                    <span class="metric-label">In lista d'attesa</span>
                </div>
                <div class="metric-value">{{ $kpiPrenotazioni['in_lista_attesa'] }}</div>
            </div>
            <div class="metric-cell">
                <div class="metric-head">
                    <span class="metric-chip"><i class="bi bi-person-vcard"></i></span>
                    <span class="metric-label">Account registrati</span>
                </div>
                <div class="metric-value">{{ $kpiPrenotazioni['account_registrati'] }}</div>
            </div>
        </div>

        <div class="panel mb-4">
            <div class="panel-header"><span>Riempimento prossimi eventi pubblicati</span></div>
            <ul class="panel-list">
                @forelse ($kpiPrenotazioni['riempimento'] as $riga)
                    <li>
                        <span>
                            {{ $riga['evento']->titolo }}
                            <span class="text-muted small">{{ $riga['evento']->data_evento?->format('d/m/Y H:i') }}</span>
                        </span>
                        <span class="text-nowrap">
                            @if ($riga['capienza'] !== null)
                                <span class="fw-semibold">{{ $riga['confermati'] }}/{{ $riga['capienza'] }}</span>
                                <span class="badge {{ $riga['percentuale'] >= 100 ? 'bg-danger' : ($riga['percentuale'] >= 80 ? 'bg-warning text-dark' : 'bg-success') }} ms-1">{{ $riga['percentuale'] }}%</span>
                            @else
                                <span class="fw-semibold">{{ $riga['confermati'] }}</span>
                                <span class="text-muted small ms-1">capienza illimitata</span>
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="panel-empty">
                        <i class="bi bi-calendar-event"></i>
                        Nessun evento pubblicato in programma.
                    </li>
                @endforelse
            </ul>
        </div>
    @endif

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
        <div class="col-12 col-lg-6">
            {{-- Top partecipanti agli eventi (f.12 piano Vita sociale) --}}
            @includeIf('eventi-vita._top-partecipanti')
        </div>
    </div>
@endsection
