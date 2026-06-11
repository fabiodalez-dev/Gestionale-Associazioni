@extends('pubblico.layouts.app')

@section('title', 'Prenotazione '.$prenotazione->codice.' — '.$associazione->nome)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Prenotazione <span class="font-monospace">{{ $prenotazione->codice }}</span></h1>
        <a href="{{ route('pubblico.account.prenotazioni.index', $associazione->slug) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Tutte le prenotazioni
        </a>
    </div>

    @include('pubblico.account._nav')

    @if ($errors->has('annulla'))
        <div class="alert alert-danger" role="alert">{{ $errors->first('annulla') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-calendar-event me-1"></i>Evento</div>
                <div class="card-body">
                    <div class="fw-semibold fs-5">{{ $evento?->titolo }}</div>
                    <div class="text-muted">
                        <i class="bi bi-calendar-event me-1"></i>{{ $evento?->data_evento?->format('d/m/Y H:i') }}
                        @if (filled($evento?->luogo))
                            &middot; <i class="bi bi-geo-alt me-1"></i>{{ $evento->luogo }}
                        @endif
                    </div>

                    <hr>

                    <dl class="row mb-0 small">
                        <dt class="col-5">Stato</dt>
                        <dd class="col-7">
                            @include('pubblico.account._stato-badge')
                            @if ($posizioneCoda !== null)
                                <span class="text-muted d-block mt-1">Posizione in coda: <strong>{{ $posizioneCoda }}</strong></span>
                            @endif
                        </dd>
                        <dt class="col-5">Posti</dt>
                        <dd class="col-7">{{ $prenotazione->posti }}</dd>
                        <dt class="col-5">Prenotata il</dt>
                        <dd class="col-7">{{ $prenotazione->created_at?->format('d/m/Y H:i') }}</dd>
                        @if ($prenotazione->checkin_at !== null)
                            <dt class="col-5">Check-in</dt>
                            <dd class="col-7">
                                <span class="badge text-bg-success">Effettuato</span>
                                {{ $prenotazione->checkin_at->format('d/m/Y H:i') }}
                            </dd>
                        @endif
                        @if ($prenotazione->annullata_at !== null)
                            <dt class="col-5">Annullata il</dt>
                            <dd class="col-7">{{ $prenotazione->annullata_at->format('d/m/Y H:i') }}
                                @if (filled($prenotazione->motivo_annullamento))
                                    <span class="text-muted d-block">{{ $prenotazione->motivo_annullamento }}</span>
                                @endif
                            </dd>
                        @endif
                    </dl>

                    @if ((float) $prenotazione->importo_totale > 0)
                        <hr>
                        <div class="fw-semibold mb-2"><i class="bi bi-credit-card me-1"></i>Pagamento</div>
                        <dl class="row mb-0 small">
                            <dt class="col-5">Importo totale</dt>
                            <dd class="col-7">&euro; {{ number_format((float) $prenotazione->importo_totale, 2, ',', '.') }}
                                <span class="text-muted">({{ $prenotazione->posti }} &times; &euro; {{ number_format((float) $prenotazione->prezzo_unitario, 2, ',', '.') }})</span>
                            </dd>
                            @if ($prenotazione->pagata_at !== null)
                                <dt class="col-5">Pagata il</dt>
                                <dd class="col-7">{{ $prenotazione->pagata_at->format('d/m/Y H:i') }}</dd>
                                <dt class="col-5">Metodo</dt>
                                <dd class="col-7 text-capitalize">{{ $prenotazione->gateway }}</dd>
                                <dt class="col-5">Transazione</dt>
                                <dd class="col-7 font-monospace text-break">{{ $prenotazione->gateway_transaction_id }}</dd>
                            @elseif ($prenotazione->stato === \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
                                <dt class="col-5">Scadenza pagamento</dt>
                                <dd class="col-7">{{ $prenotazione->scadenza_pagamento_at?->format('d/m/Y H:i') }}</dd>
                            @endif
                        </dl>
                        @if ($prenotazione->stato === \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
                            <a href="{{ route('pubblico.prenotazioni.paga', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id]) }}"
                               class="btn btn-primary btn-sm mt-3">
                                <i class="bi bi-credit-card me-1"></i>Paga ora
                            </a>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-qr-code me-1"></i>Biglietto</div>
                <div class="card-body text-center">
                    @if ($qrSvg !== null)
                        {{-- L'SVG di chillerlan ha solo il viewBox: senza width esplicita collassa a 0 --}}
                        <div class="d-inline-block bg-white p-3 rounded border mb-3 qr-wrapper" style="width: 240px;">
                            {!! $qrSvg !!}
                        </div>
                        <p class="text-muted small">Mostra questo QR all'ingresso dell'evento.</p>
                        <a href="{{ route('pubblico.account.prenotazioni.biglietto', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id]) }}"
                           class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Scarica biglietto PDF
                        </a>
                    @else
                        <p class="text-muted py-4 mb-0">
                            <i class="bi bi-qr-code display-6 d-block mb-2 opacity-50"></i>
                            Il biglietto con QR sarà disponibile quando la prenotazione sarà confermata.
                        </p>
                    @endif

                    @if ($annullabile)
                        <hr>
                        <form method="POST"
                              action="{{ route('pubblico.account.prenotazioni.annulla', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id]) }}"
                              onsubmit="return confirm('Vuoi davvero annullare questa prenotazione? L\'operazione non è reversibile.');">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-x-circle me-1"></i>Annulla prenotazione
                            </button>
                        </form>
                        @if ($prenotazione->stato === \App\Models\Prenotazione::STATO_CONFERMATA && $evento?->data_evento !== null)
                            <p class="text-muted small mt-2 mb-0">
                                Annullabile fino a {{ (int) $evento->annullamento_entro_ore }} ore prima dell'evento.
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
