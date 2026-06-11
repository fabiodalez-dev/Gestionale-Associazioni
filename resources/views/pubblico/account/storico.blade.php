@extends('pubblico.layouts.app')

@section('title', 'Storico eventi — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Storico eventi</h1>

    @include('pubblico.account._nav')

    @if ($storico->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-clock-history display-5 d-block mb-2"></i>
            Non hai ancora partecipato a nessun evento.
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
                            <th>Stato</th>
                            <th>Esito check-in</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($storico as $prenotazione)
                            <tr>
                                <td class="fw-semibold">{{ $prenotazione->evento?->titolo }}</td>
                                <td class="text-nowrap">{{ $prenotazione->evento?->data_evento?->format('d/m/Y H:i') }}</td>
                                <td>{{ $prenotazione->posti }}</td>
                                <td>@include('pubblico.account._stato-badge')</td>
                                <td>
                                    @if ($prenotazione->checkin_at !== null)
                                        <span class="badge text-bg-success">
                                            <i class="bi bi-check-lg me-1"></i>Presente
                                        </span>
                                        <span class="small text-muted d-block">{{ $prenotazione->checkin_at->format('d/m/Y H:i') }}</span>
                                    @elseif ($prenotazione->stato === \App\Models\Prenotazione::STATO_CONFERMATA)
                                        <span class="badge text-bg-secondary">Nessun check-in</span>
                                    @else
                                        <span class="text-muted small">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
