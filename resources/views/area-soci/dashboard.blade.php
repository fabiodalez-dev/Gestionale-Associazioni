@extends('area-soci.layouts.app')

@section('title', 'Area riservata - '.$socio->nome_completo)

@section('content')
    @php
        $ora = (int) now('Europe/Rome')->format('G');
        $saluto = $ora < 13 ? 'Buongiorno' : ($ora < 18 ? 'Buon pomeriggio' : 'Buonasera');
        $associazione = $socio->associazione;
    @endphp

    <div class="page-head">
        <div>
            <div class="crumb">{{ $associazione?->nome }}</div>
            <h1>{{ $saluto }}, {{ $socio->nome }}</h1>
        </div>
    </div>

    <div class="socio-hero mb-4">
        {{-- Tessera digitale --}}
        @if ($tessera !== null)
            <div class="member-card">
                <div class="mc-assoc">
                    <span class="avatar avatar-sm avatar-square">{{ mb_strtoupper(mb_substr((string) $associazione?->nome, 0, 2)) }}</span>
                    <div>
                        <b>{{ $associazione?->nome }}</b>
                        <small>Tessera socio {{ $tessera->anno_validita }}</small>
                    </div>
                    <span class="mc-state">Valida</span>
                </div>
                <div class="mc-name">{{ $socio->nome_completo }}</div>
                <div class="mc-num">{{ $tessera->numero_tessera }}</div>
                <div class="mc-meta">Socio n. {{ $socio->numero_socio }}{{ $tessera->data_scadenza ? ' · scade il '.$tessera->data_scadenza->format('d/m/Y') : '' }}</div>
                @if ($tesseraQr !== null)
                    <span class="mc-qr"><img src="{{ $tesseraQr }}" alt="QR code tessera {{ $tessera->numero_tessera }}"></span>
                @endif
                <div class="mc-actions">
                    <a href="{{ route('area-soci.tessere.pdf', ['tessera' => $tessera->id]) }}" class="btn btn-sm btn-mc"><i class="bi bi-file-earmark-pdf"></i>Scarica PDF</a>
                    <a href="{{ route('area-soci.tessere') }}" class="btn btn-sm btn-mc">Tutte le tessere</a>
                </div>
            </div>
        @else
            <div class="member-card is-empty">
                <div class="fw-semibold text-body fs-5">Nessuna tessera attiva</div>
                <p class="small mt-2 mb-3">Quando la tua iscrizione sarà rinnovata troverai qui la tessera digitale con il QR da mostrare agli eventi.</p>
                {{-- Rinnovo self-service (f.1 piano Vita sociale — modulo rinnovi) --}}
                @include('rinnovi.partials.btn-rinnova-area-soci', ['socio' => $socio])
            </div>
        @endif

        {{-- Prossima quota --}}
        <div class="card quota-card">
            <span class="icon-tile {{ $prossimaQuota !== null ? 'tone-1' : 'tone-ok' }} mb-2"><i class="bi {{ $prossimaQuota !== null ? 'bi-wallet2' : 'bi-check2-circle' }}"></i></span>
            @if ($prossimaQuota !== null)
                <div class="text-muted">La tua prossima quota</div>
                <div class="quota-importo">€ {{ number_format((float) $prossimaQuota->importo, 2, ',', '.') }}</div>
                <div class="small">{{ $prossimaQuota->tipo }} · scade il <strong class="text-body">{{ $prossimaQuota->data_scadenza?->format('d/m/Y') }}</strong></div>
                <div class="d-flex gap-2 flex-wrap mt-3">
                    @if ($prossimaQuota->hasValidPaymentToken())
                        <a class="btn btn-primary"
                           href="{{ \Illuminate\Support\Facades\Route::has('pagamento.show') ? route('pagamento.show', ['token' => $prossimaQuota->payment_token]) : url('/pagamento?token='.$prossimaQuota->payment_token) }}">
                            <i class="bi bi-credit-card"></i>Paga ora online
                        </a>
                    @endif
                    <a href="{{ route('area-soci.quote') }}" class="btn btn-outline-secondary">Le mie quote</a>
                </div>
            @else
                <div class="fw-semibold text-body fs-5">Sei in regola</div>
                <div class="text-muted">Non hai quote in scadenza: grazie per il tuo sostegno.</div>
                <div class="mt-3"><a href="{{ route('area-soci.quote') }}" class="btn btn-outline-secondary">Storico pagamenti</a></div>
            @endif
            @if ($tessera !== null)
                {{-- Rinnovo self-service (f.1 piano Vita sociale — modulo rinnovi) --}}
                <div class="mt-2">@include('rinnovi.partials.btn-rinnova-area-soci', ['socio' => $socio])</div>
            @endif
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-head pb-3">
                    <h2>I tuoi prossimi eventi</h2>
                </div>
                @if ($prossimiEventi->isEmpty())
                    <div class="panel-empty"><i class="bi bi-calendar-event"></i>Non sei iscritto a eventi in programma.</div>
                @else
                    <ul class="panel-list">
                        @foreach ($prossimiEventi as $evento)
                            <li>
                                <div class="who-cell">
                                    <span class="event-date"><b>{{ $evento->data_evento?->format('d') }}</b><small>{{ $evento->data_evento?->locale('it')->translatedFormat('M') }}</small></span>
                                    <div class="min-w-0">
                                        <div class="who-name">{{ $evento->titolo }}</div>
                                        <div class="who-sub">
                                            {{ $evento->data_evento?->format('H:i') !== '00:00' ? 'ore '.$evento->data_evento?->format('H:i') : '' }}
                                            @if ($evento->luogo)
                                                · <i class="bi bi-geo-alt"></i> {{ $evento->luogo }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
        <div class="col-lg-5 d-flex flex-column gap-4">
            <div class="card">
                <div class="card-body">
                    <div class="section-label">I miei dati</div>
                    <dl class="kv mb-3">
                        <dt>Email</dt><dd>{{ $socio->email ?: '—' }}</dd>
                        <dt>Telefono</dt><dd>{{ $socio->telefono ?: '—' }}</dd>
                        <dt>Socio dal</dt><dd>{{ $socio->data_iscrizione?->format('d/m/Y') ?? '—' }}</dd>
                    </dl>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="{{ route('area-soci.profilo') }}" class="btn btn-sm btn-outline-secondary">Il mio profilo</a>
                        @if (\Illuminate\Support\Facades\Route::has('area-soci.modifica-dati'))
                            <a href="{{ route('area-soci.modifica-dati') }}" class="btn btn-sm btn-soft">Chiedi una modifica</a>
                        @endif
                    </div>
                </div>
            </div>
            {{-- Porta un amico (f.23 piano "Organizzazione 3" — modulo relazioni) --}}
            @includeIf('relazioni.area-soci._referral-card', ['socio' => $socio])
        </div>
    </div>
@endsection
