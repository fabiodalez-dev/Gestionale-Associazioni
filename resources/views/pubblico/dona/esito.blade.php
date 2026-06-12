@extends('pubblico.layouts.app')

@section('title', 'Esito donazione — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm text-center">
                <div class="card-body p-5">
                    @if ($donazione->isPagata())
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                        <h1 class="h4 fw-bold mt-3">Grazie di cuore!</h1>
                        <p class="text-muted">
                            La tua donazione di
                            <strong>&euro; {{ number_format((float) $donazione->importo, 2, ',', '.') }}</strong>
                            a favore di {{ $associazione->nome }} è andata a buon fine.
                        </p>
                        @if (filled($donazione->donatore_email))
                            <p class="text-muted small">Riceverai la ricevuta all'indirizzo {{ $donazione->donatore_email }}.</p>
                        @endif
                    @elseif ($annullata)
                        <i class="bi bi-x-circle text-secondary" style="font-size: 3rem;"></i>
                        <h1 class="h4 fw-bold mt-3">Donazione annullata</h1>
                        <p class="text-muted">
                            Il pagamento non è stato completato. Se vuoi, puoi riprovare quando preferisci.
                        </p>
                        <a href="{{ route('pubblico.dona.show', $associazione->slug) }}" class="btn btn-primary mt-2">
                            <i class="bi bi-heart me-1"></i>Riprova a donare
                        </a>
                    @else
                        <i class="bi bi-hourglass-split text-warning" style="font-size: 3rem;"></i>
                        <h1 class="h4 fw-bold mt-3">Pagamento in elaborazione</h1>
                        <p class="text-muted">
                            Stiamo attendendo la conferma del pagamento dal circuito.
                            Riceverai la ricevuta via email appena la donazione sarà registrata.
                        </p>
                    @endif

                    <div class="mt-4">
                        <a href="{{ route('pubblico.home.show', $associazione->slug) }}" class="btn btn-outline-secondary">
                            <i class="bi bi-house me-1"></i>Torna alla home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
