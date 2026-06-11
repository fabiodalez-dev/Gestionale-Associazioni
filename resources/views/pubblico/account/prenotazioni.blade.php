@extends('pubblico.layouts.app')

@section('title', 'Le mie prenotazioni — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Le mie prenotazioni</h1>

    @include('pubblico.account._nav')

    {{-- Filtri per stato --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('pubblico.account.prenotazioni.index', $associazione->slug) }}"
           class="btn btn-sm {{ $statoFiltro === null ? 'btn-primary' : 'btn-outline-secondary' }}">Tutte</a>
        @foreach ([
            \App\Models\Prenotazione::STATO_CONFERMATA => 'Confermate',
            \App\Models\Prenotazione::STATO_IN_APPROVAZIONE => 'In approvazione',
            \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO => 'In attesa di pagamento',
            \App\Models\Prenotazione::STATO_LISTA_ATTESA => 'Lista d\'attesa',
            \App\Models\Prenotazione::STATO_ANNULLATA => 'Annullate',
            \App\Models\Prenotazione::STATO_SCADUTA => 'Scadute',
        ] as $valore => $etichetta)
            <a href="{{ route('pubblico.account.prenotazioni.index', ['associazione' => $associazione->slug, 'stato' => $valore]) }}"
               class="btn btn-sm {{ $statoFiltro === $valore ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $etichetta }}</a>
        @endforeach
    </div>

    @if ($prenotazioni->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-ticket-perforated display-5 d-block mb-2"></i>
            Nessuna prenotazione{{ $statoFiltro !== null ? ' con questo stato' : '' }}.
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Evento</th>
                            <th>Data</th>
                            <th>Posti</th>
                            <th>Codice</th>
                            <th>Stato</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($prenotazioni as $prenotazione)
                            <tr>
                                <td class="fw-semibold">{{ $prenotazione->evento?->titolo }}</td>
                                <td class="text-nowrap">{{ $prenotazione->evento?->data_evento?->format('d/m/Y H:i') }}</td>
                                <td>{{ $prenotazione->posti }}</td>
                                <td class="font-monospace">{{ $prenotazione->codice }}</td>
                                <td>
                                    @include('pubblico.account._stato-badge')
                                    @if ($posizioniCoda->has($prenotazione->id))
                                        <span class="small text-muted d-block">in coda: {{ $posizioniCoda->get($prenotazione->id) }}&ordm;</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('pubblico.account.prenotazioni.show', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id]) }}"
                                       class="btn btn-sm btn-outline-secondary">Dettaglio</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $prenotazioni->links() }}
        </div>
    @endif
@endsection
