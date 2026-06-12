{{-- Tab "Vita sociale" della scheda socio (f.12 piano Vita sociale).
     Autonomo: calcola i propri dati via VitaSocialeService per non toccare
     il SocioController di un altro modulo. --}}
@php
    /** @var \App\Models\Socio $socio */
    $vitaSociale = app(\App\Services\EventiVita\VitaSocialeService::class)->statisticheSocio($socio);
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body py-3">
            <div class="h4 mb-0">{{ $vitaSociale['prenotazioni_totali'] }}</div>
            <div class="small text-muted">Eventi prenotati</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body py-3">
            <div class="h4 mb-0 text-success">{{ $vitaSociale['prenotazioni_con_checkin'] }}</div>
            <div class="small text-muted">Partecipazioni (check-in)</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body py-3">
            <div class="h4 mb-0">{{ $vitaSociale['tasso_partecipazione'] !== null ? $vitaSociale['tasso_partecipazione'].'%' : '—' }}</div>
            <div class="small text-muted">Tasso di partecipazione</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body py-3">
            <div class="h4 mb-0">{{ $vitaSociale['ultima_presenza']?->format('d/m/Y') ?? '—' }}</div>
            <div class="small text-muted">Ultima presenza</div>
        </div></div>
    </div>
</div>

@if ($vitaSociale['account'] === null)
    <div class="alert alert-light border mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Nessun account del frontend pubblico è collegato a questo socio: le prenotazioni online non sono tracciabili.
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-6">
        <h6 class="mb-2"><i class="bi bi-ticket-perforated me-1"></i>Ultime prenotazioni online</h6>
        @if ($vitaSociale['prenotazioni']->isEmpty())
            <p class="text-muted small">Nessuna prenotazione.</p>
        @else
            <ul class="list-group">
                @foreach ($vitaSociale['prenotazioni'] as $prenotazione)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fw-semibold">{{ $prenotazione->evento?->titolo ?? 'Evento' }}</span>
                            <div class="text-muted small">{{ $prenotazione->evento?->data_evento?->format('d/m/Y H:i') }}</div>
                        </div>
                        @if ($prenotazione->checkin_at !== null)
                            <span class="badge bg-success">Presente</span>
                        @elseif ($prenotazione->stato === \App\Models\Prenotazione::STATO_CONFERMATA)
                            <span class="badge bg-primary">Confermata</span>
                        @else
                            <span class="badge bg-secondary">{{ str_replace('_', ' ', $prenotazione->stato) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    <div class="col-lg-6">
        <h6 class="mb-2"><i class="bi bi-people me-1"></i>Partecipazioni registrate dall'admin</h6>
        @if ($vitaSociale['partecipazioni_admin']->isEmpty())
            <p class="text-muted small">Nessuna partecipazione registrata.</p>
        @else
            <ul class="list-group">
                @foreach ($vitaSociale['partecipazioni_admin'] as $partecipazione)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fw-semibold">{{ $partecipazione->evento?->titolo ?? 'Evento' }}</span>
                            <div class="text-muted small">{{ $partecipazione->evento?->data_evento?->format('d/m/Y H:i') }}</div>
                        </div>
                        <span class="badge bg-success">Confermato</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
