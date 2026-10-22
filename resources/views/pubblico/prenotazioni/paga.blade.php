<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento prenotazione — {{ $associazione->nome }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        body { background: #f5f5f7; }
        .brand-header { background: #FF7B11; color: #fff; }
        .brand-header img { max-height: 56px; }
        .importo-big { font-size: 2.5rem; font-weight: 700; color: #FF7B11; }
        .codice-prenotazione { font-weight: 700; letter-spacing: .12em; color: #FF7B11; }
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
            <div class="small opacity-75">Pagamento prenotazione</div>
        </div>
        <div class="card-body p-4">

            @if (session('checkout_error'))
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ session('checkout_error') }}
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
                    Prenotazione <span class="codice-prenotazione">{{ $prenotazione->codice }}</span>
                    &middot; Posti: <strong>{{ $prenotazione->posti }}</strong>
                </div>
            </div>

            @if ($pagabile)
                <div class="text-center mb-3">
                    <div class="importo-big">&euro; {{ number_format((float) $prenotazione->importo_totale, 2, ',', '.') }}</div>
                    <div class="text-muted small">{{ $valuta }}</div>
                    @if ($prenotazione->scadenza_pagamento_at !== null)
                        <p class="text-muted mt-2 mb-0">
                            I tuoi posti restano riservati ancora per
                            <strong><span id="countdown" data-scadenza="{{ $prenotazione->scadenza_pagamento_at->toIso8601String() }}">&mdash;</span></strong>
                            (fino alle {{ $prenotazione->scadenza_pagamento_at->format('H:i') }}
                            del {{ $prenotazione->scadenza_pagamento_at->format('d/m/Y') }}).
                        </p>
                    @endif
                </div>

                @if ($hasStripe || $hasPayPal)
                    <div class="d-grid gap-2">
                        @if ($hasStripe)
                            <form method="POST"
                                  action="{{ route('pubblico.prenotazioni.checkout', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id, 'gateway' => 'stripe']) }}">
                                @csrf
                                <button type="submit" class="btn btn-brand btn-lg w-100">
                                    <i class="bi bi-credit-card me-2"></i>Paga con carta (Stripe)
                                </button>
                            </form>
                        @endif
                        @if ($hasPayPal)
                            <form method="POST"
                                  action="{{ route('pubblico.prenotazioni.checkout', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id, 'gateway' => 'paypal']) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary btn-lg w-100">
                                    <i class="bi bi-paypal me-2"></i>Paga con PayPal
                                </button>
                            </form>
                        @endif
                    </div>
                @else
                    <div class="alert alert-warning mb-0" role="alert">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Il pagamento online non è al momento disponibile.
                        Contatta l'associazione per completare la prenotazione.
                    </div>
                @endif
            @else
                {{-- In attesa di pagamento ma finestra superata: lo scheduler
                     la marcherà scaduta a breve (i posti verranno rilasciati) --}}
                <div class="text-center py-3">
                    <i class="bi bi-hourglass-bottom text-warning" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Tempo per il pagamento scaduto</h2>
                    <p class="text-muted mb-0">
                        La finestra per completare il pagamento è terminata e i posti
                        riservati verranno rilasciati. Puoi effettuare una nuova prenotazione.
                    </p>
                </div>
            @endif

            <div class="text-center mt-3 pt-3 border-top">
                <a href="{{ route('pubblico.prenotazioni.esito', ['associazione' => $associazione->slug, 'prenotazione' => $prenotazione->id]) }}"
                   class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Stato della prenotazione
                </a>
            </div>
        </div>
    </div>
</div>

@if ($pagabile && $prenotazione->scadenza_pagamento_at !== null)
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
            if (diff > 0) {
                setTimeout(tick, 1000);
            } else {
                window.location.reload();
            }
        }

        tick();
    })();
</script>
@endif
</body>
</html>
