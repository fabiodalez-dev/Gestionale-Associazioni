@extends('pubblico.layouts.app')

@section('title', 'Area riservata — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-1">Ciao, {{ $account->nome }}!</h1>
    <p class="text-muted mb-4">La tua area riservata di {{ $associazione->nome }}.</p>

    @include('pubblico.account._nav')

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-calendar-event me-1"></i>Prossime prenotazioni</span>
                    <a href="{{ route('pubblico.account.prenotazioni.index', $associazione->slug) }}" class="small">Vedi tutte</a>
                </div>
                <div class="card-body">
                    @if ($prossime->isEmpty())
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-calendar-x display-6 d-block mb-2"></i>
                            Nessuna prenotazione in programma.
                            <div class="mt-2">
                                <a href="{{ route('pubblico.eventi.index', $associazione->slug) }}" class="btn btn-sm btn-primary">
                                    Scopri gli eventi
                                </a>
                            </div>
                        </div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach ($prossime as $prenotazione)
                                <div class="list-group-item d-flex align-items-center gap-3 px-0">
                                    @if ($qrRapidi->has($prenotazione->id))
                                        {{-- QR rapido del biglietto (solo confermate) --}}
                                        <img src="{{ $qrRapidi->get($prenotazione->id) }}" alt="QR biglietto {{ $prenotazione->codice }}"
                                             width="72" height="72" class="rounded border flex-shrink-0">
                                    @endif
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">{{ $prenotazione->evento?->titolo }}</div>
                                        <div class="small text-muted">
                                            {{ $prenotazione->evento?->data_evento?->format('d/m/Y H:i') }}
                                            &middot; {{ $prenotazione->posti }} {{ $prenotazione->posti === 1 ? 'posto' : 'posti' }}
                                            &middot; <span class="font-monospace">{{ $prenotazione->codice }}</span>
                                        </div>
                                        <div class="mt-1">@include('pubblico.account._stato-badge')</div>
                                    </div>
                                    <a href="{{ route('pubblico.account.prenotazioni.show', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id]) }}"
                                       class="btn btn-sm btn-outline-secondary flex-shrink-0">Dettaglio</a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold"><i class="bi bi-person-heart me-1"></i>Socio</div>
                <div class="card-body">
                    @if ($account->socio_id !== null)
                        <p class="mb-2"><span class="badge text-bg-success">Sei socio</span></p>
                        <p class="text-muted small mb-2">Il tuo account è collegato alla tua scheda socio.</p>
                        <a href="{{ route('pubblico.account.tessera', $associazione->slug) }}" class="btn btn-sm btn-primary">
                            Tessera &amp; quote
                        </a>
                    @elseif ($richiestaIscrizione !== null && $richiestaIscrizione->isInAttesa())
                        <p class="mb-2"><span class="badge text-bg-info">Richiesta in attesa</span></p>
                        <p class="text-muted small mb-2">
                            Hai inviato la richiesta di iscrizione il
                            {{ $richiestaIscrizione->created_at->format('d/m/Y') }}:
                            l'associazione la sta valutando.
                        </p>
                        <a href="{{ route('pubblico.iscriviti', $associazione->slug) }}" class="btn btn-sm btn-outline-secondary">
                            Stato della richiesta
                        </a>
                    @elseif ($richiestaIscrizione !== null && $richiestaIscrizione->isRifiutata())
                        <p class="mb-2"><span class="badge text-bg-danger">Richiesta rifiutata</span></p>
                        @if ($richiestaIscrizione->motivo_rifiuto)
                            <p class="text-muted small mb-2">Motivo: {{ $richiestaIscrizione->motivo_rifiuto }}</p>
                        @endif
                        <a href="{{ route('pubblico.iscriviti', $associazione->slug) }}" class="btn btn-sm btn-primary">
                            Invia una nuova richiesta
                        </a>
                    @elseif ($richiestaIscrizione !== null && $richiestaIscrizione->isApprovata())
                        <p class="mb-2"><span class="badge text-bg-success">Richiesta approvata</span></p>
                        <p class="text-muted small mb-0">
                            La tua iscrizione è stata approvata: l'associazione sta
                            completando il collegamento della tua scheda socio.
                        </p>
                    @elseif ($account->richiesta_socio_at !== null)
                        <p class="mb-2"><span class="badge text-bg-info">Richiesta inviata</span></p>
                        <p class="text-muted small mb-0">
                            Hai chiesto di diventare socio il
                            {{ $account->richiesta_socio_at->format('d/m/Y') }}:
                            l'associazione ti contatterà.
                        </p>
                    @else
                        <p class="text-muted small mb-2">Vuoi far parte di {{ $associazione->nome }}?</p>
                        <a href="{{ route('pubblico.iscriviti', $associazione->slug) }}" class="btn btn-sm btn-primary">
                            Diventa socio
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
