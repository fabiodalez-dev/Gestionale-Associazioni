<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Verifica ricevuta</title>
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
                    @if ($dati === null)
                        <i class="bi bi-x-circle-fill status-icon text-danger"></i>
                        <h4 class="mt-3">Ricevuta non trovata</h4>
                        <p class="text-muted mb-0">Il codice non corrisponde a nessuna ricevuta emessa.</p>
                    @else
                        <i class="bi bi-patch-check-fill status-icon text-success"></i>
                        <h4 class="mt-3">Ricevuta valida</h4>
                        <p class="text-muted">Emessa da {{ $dati['associazione'] }}</p>
                        <table class="table table-sm text-start mb-0">
                            <tr><th>Numero</th><td>{{ $dati['numero'] }}</td></tr>
                            <tr><th>Emessa il</th><td>{{ $dati['emessa_il'] ? \Illuminate\Support\Carbon::parse($dati['emessa_il'])->format('d/m/Y') : '—' }}</td></tr>
                            <tr><th>Importo</th><td>&euro; {{ number_format((float) $dati['importo'], 2, ',', '.') }}</td></tr>
                            @if ($dati['intestatario'])
                                <tr><th>Intestata a</th><td>{{ $dati['intestatario'] }}</td></tr>
                            @endif
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</body>
</html>
