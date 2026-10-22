<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Tessera Associativa</title>
    <meta name="theme-color" content="#0D9488">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        body { background-color: #f5f6fa; }
        .verify-card { max-width: 480px; margin: 60px auto; }
        .status-icon { font-size: 4rem; }
    </style>
    @include('partials.pwa')
</head>
<body>
    <div class="container">
        <div class="verify-card">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center p-4">

                    @if ($stato === 'no_param')
                        <i class="bi bi-qr-code-scan status-icon text-muted"></i>
                        <h4 class="mt-3">Verifica Tessera Associativa</h4>
                        <p class="text-muted">Scansiona il QR code sulla tessera per verificarne la validità.</p>
                        <a href="{{ route('tessere.scanner') }}" class="btn btn-primary mt-2">
                            <i class="bi bi-camera me-1"></i> Scansiona con fotocamera
                        </a>

                    @elseif ($stato === 'not_found')
                        <i class="bi bi-exclamation-triangle status-icon text-warning"></i>
                        <h4 class="mt-3">Tessera Non Trovata</h4>
                        <p class="text-muted">Il codice scansionato non corrisponde a nessuna tessera registrata.</p>

                    @elseif ($stato === 'valida')
                        <i class="bi bi-check-circle-fill status-icon text-success"></i>
                        <h4 class="mt-3 text-success">Tessera Valida</h4>
                        <hr>
                        <div class="text-start">
                            <p><strong>Associazione:</strong> {{ $tessera->associazione?->nome }}</p>
                            <p><strong>Socio:</strong> {{ $tessera->socio?->nome }} {{ $tessera->socio?->cognome }}</p>
                            <p><strong>N. Tessera:</strong> {{ $tessera->numero_tessera }}</p>
                            <p><strong>Anno:</strong> {{ $tessera->anno_validita }}</p>
                            <p><strong>Valida fino al:</strong> {{ $tessera->data_scadenza?->format('d/m/Y') }}</p>
                        </div>

                    @elseif ($stato === 'scaduta')
                        <i class="bi bi-x-circle-fill status-icon text-danger"></i>
                        <h4 class="mt-3 text-danger">Tessera Scaduta / Non Attiva</h4>
                        <hr>
                        <div class="text-start">
                            <p><strong>Associazione:</strong> {{ $tessera->associazione?->nome }}</p>
                            <p><strong>Socio:</strong> {{ $tessera->socio?->nome }} {{ $tessera->socio?->cognome }}</p>
                            <p><strong>N. Tessera:</strong> {{ $tessera->numero_tessera }}</p>
                            <p><strong>Scadenza:</strong> {{ $tessera->data_scadenza?->format('d/m/Y') }}</p>
                            <p><strong>Stato:</strong> {{ $tessera->stato }}</p>
                        </div>
                    @endif

                </div>
            </div>
            <p class="text-center text-muted mt-3 small">{{ config('app.name') }} &mdash; Sistema di verifica tessere</p>
        </div>
    </div>
</body>
</html>
