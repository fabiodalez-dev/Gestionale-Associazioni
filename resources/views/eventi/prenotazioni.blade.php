@extends('layouts.app')

@section('title', 'Prenotazioni - '.$evento->titolo.' - '.session('app_name', config('app.name')))

@php
    use App\Models\Prenotazione;

    $badgeStato = [
        Prenotazione::STATO_CONFERMATA => ['bg-success', 'Confermata'],
        Prenotazione::STATO_IN_ATTESA_PAGAMENTO => ['bg-warning text-dark', 'In attesa di pagamento'],
        Prenotazione::STATO_LISTA_ATTESA => ['bg-info text-dark', "Lista d'attesa"],
        Prenotazione::STATO_ANNULLATA => ['bg-secondary', 'Annullata'],
        Prenotazione::STATO_SCADUTA => ['bg-dark', 'Scaduta'],
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-ticket-perforated me-2"></i>Prenotazioni</h1>
            <p class="text-muted mb-0">
                {{ $evento->titolo }} &middot; {{ $evento->data_evento?->format('d/m/Y H:i') }}
                @if ($evento->luogo) &middot; {{ $evento->luogo }} @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Eventi
            </a>
            <a href="{{ route('eventi.prenotazioni.export', array_filter(['evento' => $evento->id, 'stato' => $stato !== 'all' ? $stato : null])) }}"
               class="btn btn-outline-primary">
                <i class="bi bi-download me-1"></i>Esporta CSV
            </a>
            <form method="POST" action="{{ route('eventi.prenotazioni.promuovi', $evento) }}" class="m-0"
                  onsubmit="return confirm('Promuovere la lista d\'attesa (FIFO, nei limiti della capienza)?');">
                @csrf
                <button type="submit" class="btn btn-primary" {{ $conteggi['numero_in_coda'] === 0 ? 'disabled' : '' }}>
                    <i class="bi bi-arrow-up-circle me-1"></i>Promuovi lista d'attesa
                </button>
            </form>
        </div>
    </div>

    {{-- Conteggi in testa: confermate / riservate / coda / capienza --}}
    <div class="metrics-strip mb-4">
        <div class="metric-cell metric-ok">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-check-circle"></i></span>
                <span class="metric-label">Posti confermati</span>
            </div>
            <div class="metric-value">{{ $conteggi['posti_confermati'] }}<span class="metric-hint">{{ $conteggi['numero_confermate'] }} pren.</span></div>
        </div>
        <div class="metric-cell {{ $conteggi['posti_riservati'] > 0 ? 'metric-warn' : '' }}">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-hourglass-split"></i></span>
                <span class="metric-label">Posti riservati</span>
            </div>
            <div class="metric-value">{{ $conteggi['posti_riservati'] }}<span class="metric-hint">{{ $conteggi['numero_riservate'] }} pren.</span></div>
        </div>
        <div class="metric-cell">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-people"></i></span>
                <span class="metric-label">In lista d'attesa</span>
            </div>
            <div class="metric-value">{{ $conteggi['posti_in_coda'] }}<span class="metric-hint">{{ $conteggi['numero_in_coda'] }} pren.</span></div>
        </div>
        <div class="metric-cell {{ $conteggi['capienza'] !== null && ($conteggi['posti_confermati'] + $conteggi['posti_riservati']) > $conteggi['capienza'] ? 'metric-warn' : '' }}">
            <div class="metric-head">
                <span class="metric-chip"><i class="bi bi-grid-3x3-gap"></i></span>
                <span class="metric-label">Capienza</span>
            </div>
            <div class="metric-value">
                {{ $conteggi['capienza'] ?? 'Illimitata' }}
                @if ($conteggi['capienza'] !== null && ($conteggi['posti_confermati'] + $conteggi['posti_riservati']) > $conteggi['capienza'])
                    <span class="metric-hint text-danger" title="Overbooking: riconferme dopo scadenza pagamento">overbooking</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Filtro stato --}}
    <form method="GET" action="{{ route('eventi.prenotazioni.index', $evento) }}" class="row g-2 mb-3">
        <div class="col-12 col-md-4">
            <div class="input-group">
                <label class="input-group-text" for="stato"><i class="bi bi-funnel"></i></label>
                <select class="form-select" id="stato" name="stato" onchange="this.form.submit()">
                    <option value="all" @selected($stato === 'all')>Tutti gli stati</option>
                    @foreach ($stati as $s)
                        <option value="{{ $s }}" @selected($stato === $s)>{{ $badgeStato[$s][1] ?? ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Codice</th>
                            <th>Account</th>
                            <th class="text-center">Posti</th>
                            <th>Stato</th>
                            <th>Pagamento</th>
                            <th>Check-in</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($prenotazioni as $prenotazione)
                            @php
                                [$badgeClass, $badgeLabel] = $badgeStato[$prenotazione->stato] ?? ['bg-secondary', ucfirst($prenotazione->stato)];
                                $account = $prenotazione->account;
                            @endphp
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $prenotazione->codice }}</span>
                                    <div class="text-muted small">{{ $prenotazione->created_at?->format('d/m/Y H:i') }}</div>
                                </td>
                                <td>
                                    @if ($account)
                                        @if ($account->trashed())
                                            <span class="text-muted fst-italic">Account anonimizzato</span>
                                        @else
                                            <a href="{{ route('accounts.show', $account) }}">{{ $account->cognome }} {{ $account->nome }}</a>
                                            <div class="text-muted small">{{ $account->email }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $prenotazione->posti }}</td>
                                <td>
                                    <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                    @if ($prenotazione->stato === Prenotazione::STATO_ANNULLATA && $prenotazione->motivo_annullamento)
                                        <div class="text-muted small">{{ $prenotazione->motivo_annullamento }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ((float) $prenotazione->importo_totale > 0)
                                        € {{ number_format((float) $prenotazione->importo_totale, 2, ',', '.') }}
                                        @if ($prenotazione->pagata_at)
                                            <div class="text-success small">
                                                <i class="bi bi-check-circle me-1"></i>{{ ucfirst($prenotazione->gateway ?? 'pagata') }}
                                                {{ $prenotazione->pagata_at->format('d/m/Y H:i') }}
                                            </div>
                                        @elseif ($prenotazione->stato === Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
                                            <div class="text-warning small">
                                                <i class="bi bi-clock me-1"></i>entro {{ $prenotazione->scadenza_pagamento_at?->format('d/m/Y H:i') }}
                                            </div>
                                        @else
                                            <div class="text-muted small">Non pagata</div>
                                        @endif
                                    @else
                                        <span class="text-muted">Gratuita</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($prenotazione->checkin_at)
                                        <span class="text-success"><i class="bi bi-qr-code-scan me-1"></i>{{ $prenotazione->checkin_at->format('d/m/Y H:i') }}</span>
                                        @if ($prenotazione->checkinUtente)
                                            <div class="text-muted small">{{ $prenotazione->checkinUtente->nome_completo }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        @if (in_array($prenotazione->stato, [Prenotazione::STATO_IN_ATTESA_PAGAMENTO, Prenotazione::STATO_SCADUTA], true) && $prenotazione->pagata_at === null && (float) $prenotazione->importo_totale > 0)
                                            <form method="POST" action="{{ route('eventi.prenotazioni.conferma-pagamento', [$evento, $prenotazione->id]) }}" class="d-inline"
                                                  onsubmit="return confirm('Registrare il pagamento offline e confermare la prenotazione {{ $prenotazione->codice }}?');">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-success" title="Conferma pagamento offline">
                                                    <i class="bi bi-cash-coin"></i>
                                                </button>
                                            </form>
                                        @endif
                                        @if ($prenotazione->stato === Prenotazione::STATO_CONFERMATA)
                                            <form method="POST" action="{{ route('eventi.prenotazioni.reinvia', [$evento, $prenotazione->id]) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-primary" title="Reinvia biglietto via email">
                                                    <i class="bi bi-envelope-arrow-up"></i>
                                                </button>
                                            </form>
                                        @endif
                                        @if ($prenotazione->isAttiva() || $prenotazione->stato === Prenotazione::STATO_LISTA_ATTESA)
                                            <form method="POST" action="{{ route('eventi.prenotazioni.annulla', [$evento, $prenotazione->id]) }}" class="d-inline"
                                                  onsubmit="return confirm('Annullare la prenotazione {{ $prenotazione->codice }}? I posti liberati promuovono la lista d\'attesa.');">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-danger" title="Annulla prenotazione">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-ticket-perforated d-block mb-2" style="font-size: 2rem;"></i>
                                    @if ($stato !== 'all')
                                        Nessuna prenotazione con questo stato.
                                    @else
                                        Nessuna prenotazione per questo evento.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($prenotazioni->hasPages())
            <div class="card-footer bg-white">
                {{ $prenotazioni->links() }}
            </div>
        @endif
    </div>
@endsection
