<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento Quota — {{ $associazione?->nome ?? 'Associazione' }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        body { background: #f5f5f7; }
        .brand-header { background: #FF7B11; color: #fff; }
        .brand-header img { max-height: 56px; }
        .importo-big { font-size: 2.5rem; font-weight: 700; color: #FF7B11; }
        .btn-brand { background: #FF7B11; border-color: #FF7B11; color: #fff; }
        .btn-brand:hover { background: #e56e0a; border-color: #e56e0a; color: #fff; }
    </style>
</head>
<body>
<div class="container py-4" style="max-width: 560px;">
    <div class="card shadow-sm overflow-hidden">
        <div class="brand-header p-4 text-center">
            @if (filled($associazione?->logo_url))
                <img src="{{ $associazione->logo_url }}" alt="{{ $associazione->nome }}" class="mb-2 d-block mx-auto">
            @endif
            <h1 class="h4 mb-0">{{ $associazione?->nome ?? 'Associazione' }}</h1>
            <div class="small opacity-75">Pagamento quota associativa</div>
        </div>
        <div class="card-body p-4">

            @if ($result === 'success')
                {{-- Schermata informativa: la conferma reale arriva dal webhook --}}
                <div class="text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Pagamento completato con successo!</h2>
                    <p class="text-muted">Riceverai una conferma via email.</p>
                    @if ($alreadyPaid)
                        <a href="{{ route('pagamento.ricevuta', ['token' => $token]) }}" class="btn btn-outline-secondary mt-2">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Scarica ricevuta
                        </a>
                    @endif
                </div>
            @elseif ($tokenExpired && ! $alreadyPaid)
                <div class="text-center py-4">
                    <i class="bi bi-hourglass-bottom text-warning" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Link scaduto</h2>
                    <p class="text-muted">Questo link di pagamento non è più valido.<br>Contatta l'associazione per un nuovo link.</p>
                </div>
            @elseif ($alreadyPaid)
                <div class="text-center py-4">
                    <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                    <h2 class="h5 mt-3">Pagamento già effettuato</h2>
                    <p class="text-muted">Questa quota risulta già pagata.</p>
                    <a href="{{ route('pagamento.ricevuta', ['token' => $token]) }}" class="btn btn-outline-secondary mt-2">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Scarica ricevuta
                    </a>
                </div>
            @else
                @if ($result === 'cancel')
                    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Pagamento annullato. Puoi riprovare quando vuoi.</div>
                @endif
                @if (session('checkout_error'))
                    <div class="alert alert-danger">{{ session('checkout_error') }}</div>
                @endif

                <dl class="row mb-4">
                    <dt class="col-4 text-muted">Socio</dt>
                    <dd class="col-8">{{ $socio ? trim($socio->nome.' '.$socio->cognome) : '—' }}</dd>
                    <dt class="col-4 text-muted">Tipo</dt>
                    <dd class="col-8">{{ $quota->tipo }}</dd>
                    <dt class="col-4 text-muted">Anno</dt>
                    <dd class="col-8">{{ $quota->anno }}</dd>
                </dl>

                <div class="text-center mb-4">
                    <div class="importo-big">{{ $valuta }} {{ number_format((float) $quota->importo, 2, ',', '.') }}</div>
                </div>

                @if ($hasStripe || $hasPayPal)
                    <div class="d-grid gap-2">
                        @if ($hasStripe)
                            <form method="POST" action="{{ route('pagamento.checkout', ['token' => $token]) }}">
                                @csrf
                                <input type="hidden" name="gateway" value="stripe">
                                <button type="submit" class="btn btn-brand btn-lg w-100">
                                    <i class="bi bi-credit-card me-2"></i>Paga con Carta (Stripe)
                                </button>
                            </form>
                        @endif
                        @if ($hasPayPal)
                            <form method="POST" action="{{ route('pagamento.checkout', ['token' => $token]) }}">
                                @csrf
                                <input type="hidden" name="gateway" value="paypal">
                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    <i class="bi bi-paypal me-2"></i>Paga con PayPal
                                </button>
                            </form>
                        @endif
                    </div>
                @else
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>Nessun metodo di pagamento online configurato. Contatta l'associazione.
                    </div>
                @endif
            @endif
        </div>
        <div class="card-footer text-center small text-muted">
            &copy; {{ now()->year }} {{ $associazione?->nome ?? '' }} — Pagamento sicuro
        </div>
    </div>
</div>
</body>
</html>
