<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esito prenotazione — {{ $associazione->nome }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        body { background: #f5f5f7; }
        .brand-header { background: #FF7B11; color: #fff; }
        .brand-header img { max-height: 56px; }
        .codice-prenotazione { font-size: 1.75rem; font-weight: 700; letter-spacing: .12em; color: #FF7B11; }
        .btn-brand { background: #FF7B11; border-color: #FF7B11; color: #fff; }
        .btn-brand:hover { background: #e56e0a; border-color: #e56e0a; color: #fff; }
    </style>
</head>
<body>
<div class="container py-4" style="max-width: 560px;">
    <div class="card shadow-sm overflow-hidden">
        <div class="brand-header p-4 text-center">
            @if (filled($associazione->logoUrl()))
                <img src="{{ $associazione->logoUrl('miniatura') }}" alt="{{ $associazione->nome }}" class="mb-2 d-block mx-auto">
            @endif
            <h1 class="h4 mb-0">{{ $associazione->nome }}</h1>
            <div class="small opacity-75">Prenotazione evento</div>
        </div>
        <div class="card-body p-4">

            {{-- Ritorno dal checkout: banner informativo, fa fede lo stato live --}}
            @if (($esitoPagamento ?? null) === 'success' && $prenotazione->stato === \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
                <div class="alert alert-info" role="alert">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Pagamento ricevuto dal gateway: stiamo confermando la prenotazione,
                    questa pagina si aggiorna automaticamente.
                </div>
            @elseif (($esitoPagamento ?? null) === 'cancel' && $prenotazione->stato === \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Pagamento annullato. Puoi riprovare finché i posti restano riservati.
                </div>
            @endif

            <div class="mb-3 pb-3 border-bottom">
                <div class="fw-semibold">{{ $evento->titolo }}</div>
                <div class="text-muted small">
                    <i class="bi bi-calendar-event me-1"></i>{{ $evento->data_evento->format('d/m/Y H:i') }}
                    @if (filled($evento->luogo))
                        &middot; <i class="bi bi-geo-alt me-1"></i>{{ $evento->luogo }}
                    @endif
                </div>
                <div class="text-muted small mt-1">
                    Posti prenotati: <strong>{{ $prenotazione->posti }}</strong>
                    @if ((float) $prenotazione->importo_totale > 0)
                        &middot; Totale: <strong>&euro; {{ number_format((float) $prenotazione->importo_totale, 2, ',', '.') }}</strong>
                    @endif
                </div>
            </div>

            @if ($prenotazione->stato === \App\Models\Prenotazione::STATO_CONFERMATA)
                <div class="text-center py-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Prenotazione confermata!</h2>
                    <div class="codice-prenotazione my-2">{{ $prenotazione->codice }}</div>
                    <p class="text-muted mb-0">
                        Conserva questo codice: il biglietto con QR ti aspetta
                        nella tua area riservata e ti arriverà anche via email.
                    </p>
                </div>
            @elseif ($prenotazione->stato === \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
                <div class="text-center py-3">
                    <i class="bi bi-hourglass-split text-warning" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Posti riservati: completa il pagamento</h2>
                    <div class="codice-prenotazione my-2">{{ $prenotazione->codice }}</div>
                    @if ($prenotazione->scadenza_pagamento_at !== null)
                        <p class="text-muted">
                            I tuoi posti restano riservati fino alle
                            <strong>{{ $prenotazione->scadenza_pagamento_at->format('H:i') }}</strong>
                            del {{ $prenotazione->scadenza_pagamento_at->format('d/m/Y') }}
                            (<span id="countdown" data-scadenza="{{ $prenotazione->scadenza_pagamento_at->toIso8601String() }}">&mdash;</span>).
                        </p>
                    @endif
                    @if (Route::has('pubblico.prenotazioni.paga'))
                        <a href="{{ route('pubblico.prenotazioni.paga', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id]) }}"
                           class="btn btn-brand mt-2">
                            <i class="bi bi-credit-card me-1"></i>Paga ora
                        </a>
                    @else
                        <p class="text-muted small mb-0">Riceverai via email il link per completare il pagamento.</p>
                    @endif
                </div>
            @elseif ($prenotazione->stato === \App\Models\Prenotazione::STATO_IN_APPROVAZIONE)
                <div class="text-center py-3">
                    <i class="bi bi-person-check text-primary" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Richiesta in attesa di approvazione</h2>
                    <div class="codice-prenotazione my-2">{{ $prenotazione->codice }}</div>
                    <p class="text-muted mb-0">
                        Questo evento richiede l'approvazione dell'associazione:
                        i tuoi posti sono riservati e riceverai un'email con l'esito.
                        @if ((float) $prenotazione->importo_totale > 0)
                            Se la richiesta verrà approvata ti invieremo il link per completare il pagamento.
                        @endif
                    </p>
                </div>
            @elseif ($prenotazione->stato === \App\Models\Prenotazione::STATO_LISTA_ATTESA)
                <div class="text-center py-3">
                    <i class="bi bi-people-fill text-primary" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Sei in lista d'attesa</h2>
                    @if ($posizioneCoda !== null)
                        <p class="mb-1">Posizione in coda: <strong>{{ $posizioneCoda }}</strong></p>
                    @endif
                    <p class="text-muted mb-0">
                        L'evento è al completo. Se si liberano posti la tua prenotazione
                        verrà promossa automaticamente e riceverai un'email.
                    </p>
                </div>
            @elseif ($prenotazione->stato === \App\Models\Prenotazione::STATO_SCADUTA)
                <div class="text-center py-3">
                    <i class="bi bi-hourglass-bottom text-warning" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Prenotazione scaduta</h2>
                    <p class="text-muted mb-0">
                        Il pagamento non è arrivato entro la finestra di tempo e i posti
                        sono stati rilasciati. Puoi effettuare una nuova prenotazione.
                    </p>
                </div>
            @else
                <div class="text-center py-3">
                    <i class="bi bi-x-circle text-secondary" style="font-size: 4rem;"></i>
                    @if (filled($prenotazione->motivo_rifiuto))
                        <h2 class="h5 mt-3">Richiesta non approvata</h2>
                        <p class="text-muted mb-0">
                            L'associazione non ha approvato la tua richiesta.<br>
                            Motivo: {{ $prenotazione->motivo_rifiuto }}
                        </p>
                    @else
                        <h2 class="h5 mt-3">Prenotazione annullata</h2>
                        @if ($prenotazione->annullata_at !== null)
                            <p class="text-muted mb-0">Annullata il {{ $prenotazione->annullata_at->format('d/m/Y H:i') }}.</p>
                        @endif
                    @endif
                </div>
            @endif

            <div class="text-center mt-3 pt-3 border-top">
                <a href="{{ route('pubblico.home.show', ['associazione' => $associazione->slug]) }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Torna a {{ $associazione->nome }}
                </a>
            </div>
        </div>
    </div>
</div>

@if ($prenotazione->stato === \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO && $prenotazione->scadenza_pagamento_at !== null)
<script>
    (function () {
        var el = document.getElementById('countdown');
        if (!el) return;
        var scadenza = new Date(el.dataset.scadenza).getTime();

        function tick() {
            var diff = Math.max(0, Math.floor((scadenza - Date.now()) / 1000));
            var m = Math.floor(diff / 60);
            var s = diff % 60;
            el.textContent = diff > 0
                ? m + "' " + (s < 10 ? '0' : '') + s + '"'
                : 'tempo scaduto';
            if (diff > 0) setTimeout(tick, 1000);
        }

        tick();
    })();
</script>
@endif
@if (($esitoPagamento ?? null) === 'success' && $prenotazione->stato === \App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
<script>
    // Il webhook di conferma può arrivare dopo il redirect del gateway:
    // ricarica (senza query string) finché lo stato non si aggiorna
    setTimeout(function () {
        window.location.replace(window.location.pathname);
    }, 5000);
</script>
@endif
</body>
</html>
