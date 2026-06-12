@extends('area-soci.layouts.app')

@section('title', 'Area Riservata - '.$socio->nome_completo)

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Ciao, {{ $socio->nome }}!</h1>
    </div>

    <div class="row g-4">
        {{-- Prossima quota --}}
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-cash-coin me-2"></i>La tua prossima quota</div>
                <div class="card-body">
                    @if ($prossimaQuota !== null)
                        <p class="mb-2">
                            La tua prossima quota di
                            <strong>&euro; {{ number_format((float) $prossimaQuota->importo, 2, ',', '.') }}</strong>
                            scade il <strong>{{ $prossimaQuota->data_scadenza?->format('d/m/Y') }}</strong>.
                        </p>
                        <p class="text-muted small mb-3">{{ $prossimaQuota->tipo }}</p>
                        @if ($prossimaQuota->hasValidPaymentToken())
                            <a class="btn btn-primary btn-sm"
                               href="{{ \Illuminate\Support\Facades\Route::has('pagamento.show') ? route('pagamento.show', ['token' => $prossimaQuota->payment_token]) : url('/pagamento?token='.$prossimaQuota->payment_token) }}">
                                <i class="bi bi-credit-card me-1"></i> Paga ora online
                            </a>
                        @else
                            <a href="{{ route('area-soci.quote') }}" class="btn btn-outline-primary btn-sm">Vedi le mie quote</a>
                        @endif
                    @else
                        <p class="mb-0 text-success">
                            <i class="bi bi-check-circle me-1"></i>
                            Non hai quote in scadenza. Sei in regola con i pagamenti!
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Tessera con QR --}}
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-credit-card-2-front me-2"></i>La tua tessera</div>
                <div class="card-body">
                    @if ($tessera !== null)
                        <div class="d-flex align-items-center gap-3">
                            <div class="flex-grow-1">
                                <p class="mb-2">
                                    La tua tessera <strong>N. {{ $tessera->numero_tessera }}</strong>
                                    è <span class="badge bg-success">Attiva</span>
                                    fino al <strong>{{ $tessera->data_scadenza?->format('d/m/Y') }}</strong>.
                                </p>
                                <a href="{{ route('area-soci.tessere.pdf', ['tessera' => $tessera->id]) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> Scarica PDF
                                </a>
                            </div>
                            @if ($tesseraQr !== null)
                                <img src="{{ $tesseraQr }}" alt="QR code tessera {{ $tessera->numero_tessera }}" width="110" height="110" class="border rounded p-1 bg-white">
                            @endif
                        </div>
                    @else
                        <p class="mb-0 text-muted">
                            Nessuna tessera attiva. Contatta la tua associazione per maggiori informazioni.
                        </p>
                    @endif

                    {{-- Rinnovo self-service (f.1 piano Vita sociale — modulo rinnovi) --}}
                    @include('rinnovi.partials.btn-rinnova-area-soci', ['socio' => $socio])
                </div>
            </div>
        </div>

        {{-- Prossimi eventi --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header"><i class="bi bi-calendar-event me-2"></i>I tuoi prossimi eventi</div>
                <div class="card-body">
                    @if ($prossimiEventi->isEmpty())
                        <p class="mb-0 text-muted">Non sei iscritto a eventi in programma.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($prossimiEventi as $evento)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <strong>{{ $evento->titolo }}</strong>
                                        @if ($evento->luogo)
                                            <span class="text-muted small ms-2"><i class="bi bi-geo-alt"></i> {{ $evento->luogo }}</span>
                                        @endif
                                    </div>
                                    <span class="badge bg-light text-dark">{{ $evento->data_evento?->format('d/m/Y H:i') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
