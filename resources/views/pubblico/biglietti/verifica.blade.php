<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Biglietto</title>
    <meta name="theme-color" content="#0D9488">
    <meta name="robots" content="noindex, nofollow">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        body { background-color: #f5f6fa; }
        .verify-card { max-width: 480px; margin: 60px auto; }
        .status-icon { font-size: 4rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="verify-card">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center p-4">

                    @if ($stato === 'valida')
                        <i class="bi bi-check-circle-fill status-icon text-success"></i>
                        <h4 class="mt-3 text-success">Biglietto valido</h4>
                        <hr>
                        <div class="text-start">
                            <p><strong>Associazione:</strong> {{ $prenotazione->associazione?->nome }}</p>
                            <p><strong>Evento:</strong> {{ $prenotazione->evento?->titolo }}</p>
                            <p><strong>Data:</strong> {{ $prenotazione->evento?->data_evento?->format('d/m/Y H:i') }}</p>
                            @if ($prenotazione->evento?->luogo)
                                <p><strong>Luogo:</strong> {{ $prenotazione->evento->luogo }}</p>
                            @endif
                            <p><strong>Intestatario:</strong> {{ $nominativo }}</p>
                            <p><strong>Posti:</strong> {{ $prenotazione->posti }}</p>
                            <p><strong>Codice:</strong> {{ $prenotazione->codice }}</p>
                        </div>

                    @elseif ($stato === 'gia_usato')
                        <i class="bi bi-exclamation-circle-fill status-icon text-warning"></i>
                        <h4 class="mt-3 text-warning">Biglietto già utilizzato</h4>
                        <p class="text-muted">
                            Check-in effettuato alle <strong>{{ $prenotazione->checkin_at?->format('H:i') }}</strong>
                            del {{ $prenotazione->checkin_at?->format('d/m/Y') }}.
                        </p>
                        <hr>
                        <div class="text-start">
                            <p><strong>Evento:</strong> {{ $prenotazione->evento?->titolo }}</p>
                            <p><strong>Intestatario:</strong> {{ $nominativo }}</p>
                            <p><strong>Posti:</strong> {{ $prenotazione->posti }}</p>
                            <p><strong>Codice:</strong> {{ $prenotazione->codice }}</p>
                        </div>

                    @else
                        <i class="bi bi-x-circle-fill status-icon text-danger"></i>
                        <h4 class="mt-3 text-danger">Biglietto non valido</h4>
                        <p class="text-muted">{{ $motivo }}</p>
                        @if ($prenotazione !== null)
                            <hr>
                            <div class="text-start">
                                <p><strong>Evento:</strong> {{ $prenotazione->evento?->titolo }}</p>
                                <p><strong>Codice:</strong> {{ $prenotazione->codice }}</p>
                            </div>
                        @endif
                    @endif

                </div>
            </div>
            <p class="text-center text-muted mt-3 small">{{ config('app.name') }} &mdash; Sistema di verifica biglietti</p>
        </div>
    </div>
</body>
</html>
