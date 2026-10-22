{{--
    Ricevuta di pagamento quota NUMERATA per anno (f.21 PIANO-VITA-SOCIALE).
    Variabili: $ricevuta (Ricevuta), $quota (?Quota), $associazione (?Associazione), $socio (?Socio).
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Ricevuta n. {{ $ricevuta->numero }}/{{ $ricevuta->anno }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 12px; margin-bottom: 24px; }
        .header h1 { color: #0D9488; font-size: 20px; margin: 0 0 4px 0; }
        .header .assoc { font-size: 14px; font-weight: bold; }
        .numero { font-size: 14px; font-weight: bold; margin: 12px 0; }
        table.dettagli { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.dettagli th, table.dettagli td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; }
        table.dettagli th { background: #f8f9fa; width: 40%; }
        .importo { font-size: 18px; font-weight: bold; color: #0D9488; }
        .footer { margin-top: 40px; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Ricevuta di pagamento n. {{ $ricevuta->numero }}/{{ $ricevuta->anno }}</h1>
        <div class="assoc">{{ $associazione?->nome ?? 'Associazione' }}</div>
        @if (filled($associazione?->indirizzo))
            <div>{{ $associazione->indirizzo }}@if (filled($associazione->citta)), {{ $associazione->cap }} {{ $associazione->citta }} ({{ $associazione->provincia }})@endif</div>
        @endif
        @if (filled($associazione?->codice_fiscale))
            <div>C.F. {{ $associazione->codice_fiscale }}</div>
        @endif
    </div>

    <div class="numero">
        Ricevuta n. {{ $ricevuta->numero }}/{{ $ricevuta->anno }} &mdash; emessa il {{ $ricevuta->emessa_at?->format('d/m/Y') ?? now()->format('d/m/Y') }}
    </div>

    <p>
        Si attesta che <strong>{{ $socio !== null ? trim($socio->nome.' '.$socio->cognome) : '—' }}</strong>
        @if (filled($socio?->numero_socio)) (socio n. {{ $socio->numero_socio }}) @endif
        ha versato la quota di seguito dettagliata.
    </p>

    <table class="dettagli">
        <tr><th>Tipo quota</th><td>{{ $quota?->tipo ?? 'Quota Associativa' }}</td></tr>
        <tr><th>Anno di riferimento</th><td>{{ $ricevuta->anno }}</td></tr>
        <tr><th>Importo</th><td class="importo">&euro; {{ number_format((float) $ricevuta->importo, 2, ',', '.') }}</td></tr>
        <tr><th>Data pagamento</th><td>{{ $quota?->data_pagamento?->format('d/m/Y') ?? '—' }}</td></tr>
        <tr><th>Metodo di pagamento</th><td>{{ ucfirst((string) ($quota?->metodo_pagamento ?? '—')) }}</td></tr>
        @if (filled($quota?->gateway_transaction_id))
            <tr><th>Riferimento transazione</th><td>{{ $quota->gateway_transaction_id }}</td></tr>
        @endif
    </table>

    <div class="footer">
        Ricevuta n. {{ $ricevuta->numero }}/{{ $ricevuta->anno }} generata il {{ now()->format('d/m/Y H:i') }} — {{ $associazione?->nome }}.
        Documento non valido ai fini fiscali se non accompagnato dalle registrazioni contabili dell'associazione.
    </div>
</body>
</html>
