@extends('layouts.app')

@section('title', 'Dashboard - '.session('app_name', config('app.name')))

@section('content')
    @php
        $p = $panoramica;
        $ora = (int) now('Europe/Rome')->format('G');
        $saluto = $ora < 13 ? 'Buongiorno' : ($ora < 18 ? 'Buon pomeriggio' : 'Buonasera');
        $euro = fn (float $v) => '€ '.number_format($v, $v >= 1000 ? 0 : 2, ',', '.');
        $nomeAssociazione = $vistaGlobale ? 'tutte le associazioni' : session('associazione_nome', 'la tua associazione');
        $iniziali = fn (?string $t) => mb_strtoupper(collect(preg_split('/\s+/', trim((string) $t)) ?: [])->filter()->take(2)->map(fn ($x) => mb_substr($x, 0, 1))->join('')) ?: '?';
        $circonferenza = 2 * M_PI * 52;
        $kpiVisibili = (int) ($p['incassi'] !== null) + (int) ($p['nuovi'] !== null);
    @endphp

    <div class="page-head">
        <div>
            <div class="crumb">Panoramica di {{ $nomeAssociazione }}</div>
            <h1>Dashboard</h1>
        </div>
        <div class="text-muted small">Aggiornata alle {{ now('Europe/Rome')->format('H:i') }} del {{ now('Europe/Rome')->format('d/m/Y') }}</div>
    </div>

    {{-- Card onboarding admin: checklist configurazione tenant (piano Gestione 2, f.23) --}}
    @includeIf('piattaforma._onboarding-card')

    {{-- Alert mandati organi sociali in scadenza (piano vita sociale, f.16) --}}
    @if (($caricheInScadenza ?? null) !== null && $caricheInScadenza->isNotEmpty())
        <div class="alert alert-warning d-flex gap-3">
            <i class="bi bi-exclamation-triangle fs-5"></i>
            <div>
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
        </div>
    @endif

    {{-- Riga 1: saluto con tesseramento + due KPI --}}
    <div class="dash-grid dash-row-1 dash-kpi-{{ $kpiVisibili }} mb-4">
        <div class="card hero-card">
            <div>
                <h2>{{ $saluto }} {{ $utente->nome }}, bentornato</h2>
                @php
                    $frasi = [];
                    if ($p['vedi_tessere'] && $stats['scadenze_imminenti'] > 0) {
                        $frasi[] = '<strong>'.$stats['scadenze_imminenti'].' '.($stats['scadenze_imminenti'] === 1 ? 'tessera in scadenza' : 'tessere in scadenza').'</strong> nei prossimi 30 giorni';
                    }
                    if (($p['richieste_in_attesa'] ?? 0) > 0) {
                        $frasi[] = '<strong>'.$p['richieste_in_attesa'].' '.($p['richieste_in_attesa'] === 1 ? 'richiesta di iscrizione' : 'richieste di iscrizione').'</strong> da approvare';
                    }
                @endphp
                <p>
                    @if ($frasi !== [])
                        {{-- solo numeri e testo fisso: nessun dato utente nelle frasi --}}
                        Hai {!! implode(' e ', $frasi) !!}.
                    @else
                        Niente in sospeso: nessuna scadenza imminente e nessuna richiesta da approvare.
                    @endif
                </p>
                <div class="hero-actions">
                    @if (\Illuminate\Support\Facades\Route::has('campagna-rinnovi.index') && ($utente->isSuperAdmin() || $utente->can('tessere.view')))
                        <a href="{{ route('campagna-rinnovi.index') }}" class="btn btn-primary"><i class="bi bi-arrow-repeat"></i>Campagna rinnovi</a>
                    @endif
                    @if (\Illuminate\Support\Facades\Route::has('richieste-iscrizione.index') && ($utente->isSuperAdmin() || $utente->can('iscrizioni.view')))
                        <a href="{{ route('richieste-iscrizione.index') }}" class="btn btn-soft">Approva adesioni</a>
                    @endif
                </div>
            </div>
            @if ($p['vedi_tessere'])
            <div class="hero-ring">
                <div class="ring" role="img" aria-label="{{ $p['percentuale_tesserati'] }}% dei soci attivi ha la tessera valida">
                    <svg viewBox="0 0 124 124" aria-hidden="true">
                        <circle class="ring-track" cx="62" cy="62" r="52" fill="none" stroke-width="12"/>
                        <circle class="ring-value" cx="62" cy="62" r="52" fill="none" stroke-width="12"
                                stroke-dasharray="{{ $circonferenza }}" stroke-dashoffset="{{ $circonferenza * (1 - $p['percentuale_tesserati'] / 100) }}"/>
                    </svg>
                    <div class="ring-label"><b>{{ $p['percentuale_tesserati'] }}%</b><span>tesserati</span></div>
                </div>
                <div class="ring-caption">{{ number_format($p['tesserati'], 0, ',', '.') }} su {{ number_format($p['soci_attivi'], 0, ',', '.') }} soci attivi</div>
            </div>
            @endif
        </div>

        @if ($p['incassi'] !== null)
        <div class="card kpi-card">
            <span class="icon-tile tone-ok"><i class="bi bi-currency-euro"></i></span>
            <div class="kpi-label">Quote incassate {{ now()->year }}</div>
            <div class="kpi-value">{{ $euro($p['incassi']['incassato_anno']) }}</div>
            @if ($p['incassi']['variazione'] !== null)
                <div class="kpi-trend {{ $p['incassi']['variazione'] >= 0 ? 'metric-up' : 'metric-down' }}">
                    {{ $p['incassi']['variazione'] >= 0 ? '+' : '' }}{{ number_format($p['incassi']['variazione'], 1, ',', '.') }}% sul {{ now()->year - 1 }}
                </div>
            @else
                <div class="kpi-trend text-muted">{{ $stats['quote_pagate'] }} {{ $stats['quote_pagate'] === 1 ? 'quota pagata' : 'quote pagate' }}</div>
            @endif
        </div>
        @endif

        @if ($p['nuovi'] !== null)
        <div class="card kpi-card">
            <span class="icon-tile tone-2"><i class="bi bi-person-plus"></i></span>
            <div class="kpi-label">Nuovi soci ({{ \Illuminate\Support\Str::lower(now()->locale('it')->translatedFormat('F')) }})</div>
            <div class="kpi-value">{{ $p['nuovi']['mese'] }}</div>
            @if ($p['nuovi']['variazione'] !== null)
                <div class="kpi-trend {{ $p['nuovi']['variazione'] >= 0 ? 'metric-up' : 'metric-down' }}">
                    {{ $p['nuovi']['variazione'] >= 0 ? '+' : '' }}{{ number_format($p['nuovi']['variazione'], 1, ',', '.') }}% sul mese scorso
                </div>
            @else
                <div class="kpi-trend text-muted">{{ number_format($stats['total_soci'], 0, ',', '.') }} soci in totale</div>
            @endif
        </div>
        @endif
    </div>

    {{-- Riga 2: andamento mensile + composizione --}}
    @if ($p['andamento'] !== null)
    <div class="dash-grid dash-row-2 mb-4">
        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Iscrizioni e tessere</h2>
                    <div class="sub">Ultimi 12 mesi · {{ $vistaGlobale ? 'tutte le associazioni' : session('associazione_nome') }}</div>
                </div>
                <div class="legend">
                    <span><i style="background: var(--ds-accent)"></i>Nuovi soci</span>
                    <span><i style="background: var(--ds-accent-mid)"></i>Tessere emesse</span>
                </div>
            </div>
            @php $max = $p['andamento']['massimo']; @endphp
            <div class="bars">
                <div class="bars-axis" aria-hidden="true">
                    <span>{{ $max }}</span><span>{{ (int) round($max * 2 / 3) }}</span><span>{{ (int) round($max / 3) }}</span><span>0</span>
                </div>
                <div class="bars-plot">
                    @foreach ($p['andamento']['mesi'] as $m)
                        <div class="bars-col" tabindex="0" aria-label="{{ $m['etichetta'] }} {{ $m['anno'] }}: {{ $m['nuovi'] }} nuovi soci, {{ $m['tessere'] }} tessere emesse">
                            <span class="bar" style="height: {{ round($m['nuovi'] * 100 / $max, 1) }}%"></span>
                            <span class="bar alt" style="height: {{ round($m['tessere'] * 100 / $max, 1) }}%"></span>
                            <span class="tip">{{ $m['etichetta'] }} {{ $m['anno'] }} · {{ $m['nuovi'] }} nuovi · {{ $m['tessere'] }} tessere</span>
                        </div>
                    @endforeach
                </div>
                <div class="bars-labels" aria-hidden="true">
                    @foreach ($p['andamento']['mesi'] as $m)
                        <span>{{ $m['etichetta'] }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Composizione soci</h2>
                    <div class="sub">Soci attivi per categoria</div>
                </div>
            </div>
            <div class="px-4 pb-4">
                <div class="d-flex align-items-baseline gap-2 mt-2">
                    <span class="fs-2 fw-semibold text-body lh-1">{{ number_format($p['soci_attivi'], 0, ',', '.') }}</span>
                    @if (($p['nuovi']['mese'] ?? 0) > 0)
                        <span class="small metric-up fw-medium">+{{ $p['nuovi']['mese'] }} questo mese</span>
                    @endif
                </div>
                @if ($p['composizione'] === [])
                    <div class="panel-empty px-0"><i class="bi bi-pie-chart"></i>Nessun socio attivo.</div>
                @else
                    <div class="stack-bar" aria-hidden="true">
                        @foreach ($p['composizione'] as $c)
                            <span class="c-{{ min($loop->index, 5) }}" style="width: {{ max(1, $c['percentuale']) }}%"></span>
                        @endforeach
                    </div>
                    @foreach (array_slice($p['composizione'], 0, 5) as $c)
                        <div class="comp-row">
                            <span class="pct tone-{{ [0, 2, 1, 3, 5, 4][min($loop->index, 5)] }}">{{ $c['percentuale'] }}%</span>
                            <div>
                                <div class="name">{{ $c['nome'] }}</div>
                            </div>
                            <span class="num">{{ number_format($c['numero'], 0, ',', '.') }}</span>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>

    @endif

    {{-- Riga 3: rinnovi, attività recente, eventi / associazioni --}}
    <div class="dash-grid dash-row-3 mb-4">
        @if ($p['rinnovi'] !== null)
        <div class="card">
            <div class="card-head pb-3">
                <h2>Rinnovi in scadenza</h2>
                @if (\Illuminate\Support\Facades\Route::has('campagna-rinnovi.index'))
                    <a href="{{ route('campagna-rinnovi.index') }}">Vedi tutti</a>
                @endif
            </div>
            @if ($p['rinnovi']->isEmpty())
                <div class="panel-empty"><i class="bi bi-check2-circle"></i>Nessuna tessera da rinnovare nei prossimi 30 giorni.</div>
            @else
                <ul class="panel-list">
                    @foreach ($p['rinnovi'] as $tessera)
                        @php
                            $giorni = (int) now()->startOfDay()->diffInDays($tessera->data_scadenza, false);
                            $socioRinnovo = $tessera->socio;
                        @endphp
                        <li>
                            <div class="who-cell">
                                <span class="avatar avatar-sm tone-{{ $loop->index % 6 }}">{{ $iniziali($socioRinnovo?->nome.' '.$socioRinnovo?->cognome) }}</span>
                                <div class="min-w-0">
                                    @if ($socioRinnovo)
                                        <a class="who-name d-block text-truncate" href="{{ route('soci.show', $socioRinnovo) }}">{{ $socioRinnovo->nome }} {{ $socioRinnovo->cognome }}</a>
                                    @endif
                                    <div class="who-sub">{{ $socioRinnovo?->categoriaSocio?->nome ?? 'Tessera '.$tessera->numero_tessera }}</div>
                                </div>
                            </div>
                            <span class="small text-nowrap {{ $giorni < 0 ? 'text-danger' : ($giorni <= 7 ? 'text-warning' : 'text-muted') }}">
                                @if ($giorni < 0)
                                    scaduta da {{ abs($giorni) }} gg
                                @elseif ($giorni === 0)
                                    scade oggi
                                @else
                                    tra {{ $giorni }} gg
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        @endif

        <div class="card">
            <div class="card-head pb-3">
                <h2>Attività recente</h2>
            </div>
            @if ($p['attivita']->isEmpty())
                <div class="panel-empty"><i class="bi bi-activity"></i>Ancora nessuna attività.</div>
            @else
                <ul class="timeline">
                    @foreach ($p['attivita'] as $voce)
                        <li>
                            <span class="tl-dot {{ $voce['tipo'] === 'ok' ? '' : $voce['tipo'] }}"></span>
                            <div class="tl-head">
                                <span class="tl-title">{{ $voce['titolo'] }}</span>
                                <span class="tl-time">{{ $voce['quando']->locale('it')->shortRelativeDiffForHumans() }}</span>
                            </div>
                            <div class="tl-sub">{{ $voce['dettaglio'] }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($vistaGlobale)
            <div class="card">
                <div class="card-head pb-2">
                    <h2>Associazioni</h2>
                    <a href="{{ route('associazioni.index') }}">Gestisci</a>
                </div>
                <div class="px-4 pb-3">
                    @php $maxSoci = max(1, (int) $associazioniGlobali->max('soci_count')); @endphp
                    @forelse ($associazioniGlobali->take(6) as $a)
                        <form method="POST" action="{{ route('tenant.switch') }}" class="m-0">
                            @csrf
                            <input type="hidden" name="associazione_id" value="{{ $a->id }}">
                            <button type="submit" class="meter-row w-100 border-0 bg-transparent text-start p-0 py-2" title="Entra in {{ $a->nome }}">
                                <span class="avatar avatar-sm avatar-square tone-{{ $loop->index % 6 }}">{{ $iniziali($a->nome) }}</span>
                                <span class="meter-body">
                                    <span class="meter-name d-block">{{ $a->nome }}</span>
                                    <span class="progress d-flex"><span class="progress-bar" style="width: {{ round($a->soci_count * 100 / $maxSoci) }}%"></span></span>
                                </span>
                                <span class="meter-num">{{ number_format($a->soci_count, 0, ',', '.') }}<small class="text-muted">soci</small></span>
                            </button>
                        </form>
                    @empty
                        <div class="panel-empty px-0"><i class="bi bi-buildings"></i>Nessuna associazione attiva.</div>
                    @endforelse
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-head pb-3">
                    <h2>Prossimi eventi</h2>
                    @if (\Illuminate\Support\Facades\Route::has('eventi.index'))
                        <a href="{{ route('eventi.index') }}">Tutti</a>
                    @endif
                </div>
                <ul class="panel-list">
                    @forelse ($prossimiEventi as $evento)
                        <li>
                            <div class="who-cell">
                                <span class="icon-tile icon-tile-sm tone-0 flex-column lh-1" style="font-size: 0.6875rem">
                                    <b style="font-size: 0.9375rem">{{ $evento->data_evento?->format('d') }}</b>{{ \Illuminate\Support\Str::lower($evento->data_evento?->locale('it')->translatedFormat('M')) }}
                                </span>
                                <span class="who-name text-truncate">{{ $evento->titolo }}</span>
                            </div>
                            <span class="text-muted small text-nowrap">{{ $evento->data_evento?->format('H:i') !== '00:00' ? $evento->data_evento?->format('H:i') : '' }}</span>
                        </li>
                    @empty
                        <li class="panel-empty">
                            <i class="bi bi-calendar-event"></i>
                            Nessun evento in programma. Creane uno da Eventi.
                        </li>
                    @endforelse
                </ul>
            </div>
        @endif
    </div>

    @if ($kpiPrenotazioni !== null)
        {{-- KPI prenotazioni frontend pubblico (solo se attivo per il tenant) --}}
        <h2 class="h5 mb-3">Prenotazioni frontend</h2>
        <div class="metrics-strip mb-4">
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
            <div class="metric-cell {{ $kpiPrenotazioni['in_lista_attesa'] > 0 ? 'metric-warn' : 'metric-info' }}">
                <div class="metric-head">
                    <span class="metric-chip"><i class="bi bi-hourglass-split"></i></span>
                    <span class="metric-label">In lista d'attesa</span>
                </div>
                <div class="metric-value">{{ $kpiPrenotazioni['in_lista_attesa'] }}</div>
            </div>
            <div class="metric-cell metric-info">
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
                        <span class="flex-grow-1 min-w-0">
                            <span class="text-body fw-medium">{{ $riga['evento']->titolo }}</span>
                            <span class="text-muted small ms-1">{{ $riga['evento']->data_evento?->format('d/m/Y H:i') }}</span>
                            @if ($riga['capienza'] !== null)
                                <span class="progress mt-2" style="max-width: 420px"><span class="progress-bar {{ $riga['percentuale'] >= 100 ? 'bg-danger' : ($riga['percentuale'] >= 80 ? 'bg-warning' : '') }}" style="width: {{ $riga['percentuale'] }}%"></span></span>
                            @endif
                        </span>
                        <span class="text-nowrap">
                            @if ($riga['capienza'] !== null)
                                <span class="fw-semibold text-body">{{ $riga['confermati'] }}/{{ $riga['capienza'] }}</span>
                                <span class="badge {{ $riga['percentuale'] >= 100 ? 'bg-danger' : ($riga['percentuale'] >= 80 ? 'bg-warning' : 'bg-success') }} ms-1">{{ $riga['percentuale'] }}%</span>
                            @else
                                <span class="fw-semibold text-body">{{ $riga['confermati'] }}</span>
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

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            {{-- Le mie attività (f.4 piano "Gestione 2" — modulo operativita) --}}
            @includeIf('operativita._widget-attivita')
        </div>
        <div class="col-12 col-xl-4">
            {{-- Top partecipanti agli eventi (f.12 piano Vita sociale) --}}
            @includeIf('eventi-vita._top-partecipanti')
        </div>
        <div class="col-12 col-xl-4">
            {{-- Compleanni del mese (f.9 piano "Gestione 2" — modulo comunicazione) --}}
            @includeIf('comunicazione.dashboard._compleanni')
        </div>
    </div>
@endsection
