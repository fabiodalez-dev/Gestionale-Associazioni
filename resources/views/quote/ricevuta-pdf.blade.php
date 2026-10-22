<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Ricevuta quota {{ $quota->anno }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 12px; margin-bottom: 24px; }
        .header h1 { color: #0D9488; font-size: 20px; margin: 0 0 4px 0; }
        .header .assoc { font-size: 14px; font-weight: bold; }
        table.dettagli { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.dettagli th, table.dettagli td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; }
        table.dettagli th { background: #f8f9fa; width: 40%; }
        .importo { font-size: 18px; font-weight: bold; color: #0D9488; }
        .footer { margin-top: 40px; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Ricevuta di pagamento</h1>
        <div class="assoc">{{ $quota->associazione?->nome ?? 'Associazione' }}</div>
        @if (filled($quota->associazione?->indirizzo))
            <div>{{ $quota->associazione->indirizzo }}@if (filled($quota->associazione->citta)), {{ $quota->associazione->cap }} {{ $quota->associazione->citta }} ({{ $quota->associazione->provincia }})@endif</div>
        @endif
        @if (filled($quota->associazione?->codice_fiscale))
            <div>C.F. {{ $quota->associazione->codice_fiscale }}</div>
        @endif
    </div>

    <p>
        Si attesta che <strong>{{ $quota->socio ? trim($quota->socio->nome.' '.$quota->socio->cognome) : '—' }}</strong>
        ha versato la quota di seguito dettagliata.
    </p>

    <table class="dettagli">
        <tr><th>Tipo quota</th><td>{{ $quota->tipo }}</td></tr>
        <tr><th>Anno di riferimento</th><td>{{ $quota->anno }}</td></tr>
        <tr><th>Importo</th><td class="importo">&euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}</td></tr>
        <tr><th>Data pagamento</th><td>{{ $quota->data_pagamento?->format('d/m/Y') }}</td></tr>
        <tr><th>Metodo di pagamento</th><td>{{ ucfirst((string) $quota->metodo_pagamento) }}</td></tr>
        @if (filled($quota->gateway_transaction_id))
            <tr><th>Riferimento transazione</th><td>{{ $quota->gateway_transaction_id }}</td></tr>
        @endif
    </table>

    <div class="footer">
        Ricevuta generata il {{ now()->format('d/m/Y H:i') }} — {{ $quota->associazione?->nome }}.
        Documento non valido ai fini fiscali se non accompagnato dalle registrazioni contabili dell'associazione.
    </div>
</body>
</html>
