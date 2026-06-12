<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Foglio presenze — {{ $evento->titolo }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #222; margin: 24px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 18px 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
        .meta { color: #555; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; vertical-align: middle; }
        th { background: #eee; font-size: 9px; text-transform: uppercase; }
        .firma { width: 32%; height: 26px; }
        .center { text-align: center; }
        .footer { margin-top: 18px; color: #777; font-size: 9px; }
    </style>
</head>
<body>
    <h1>Foglio presenze</h1>
    <div class="meta">
        <strong>{{ $associazione?->nome }}</strong><br>
        Evento: <strong>{{ $evento->titolo }}</strong> —
        {{ $evento->data_evento->format('d/m/Y \o\r\e H:i') }}
        @if (filled($evento->luogo)) — {{ $evento->luogo }} @endif
    </div>

    <h2>Prenotazioni dal sito ({{ $prenotazioni->count() }})</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 26px;">#</th>
                <th>Nominativo</th>
                <th style="width: 70px;">Codice</th>
                <th class="center" style="width: 36px;">Posti</th>
                <th class="center" style="width: 70px;">Check-in</th>
                <th class="firma">Firma</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($prenotazioni as $prenotazione)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $prenotazione->account?->nome_completo ?: '—' }}</td>
                    <td>{{ $prenotazione->codice }}</td>
                    <td class="center">{{ $prenotazione->posti }}</td>
                    <td class="center">{{ $prenotazione->checkin_at?->format('H:i') ?? '' }}</td>
                    <td class="firma"></td>
                </tr>
            @empty
                <tr><td colspan="6" class="center">Nessuna prenotazione confermata.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Partecipanti soci confermati ({{ $partecipanti->count() }})</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 26px;">#</th>
                <th>Socio</th>
                <th style="width: 80px;">N. socio</th>
                <th class="firma">Firma</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($partecipanti as $partecipante)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $partecipante->socio?->cognome }} {{ $partecipante->socio?->nome }}</td>
                    <td>{{ $partecipante->socio?->numero_socio }}</td>
                    <td class="firma"></td>
                </tr>
            @empty
                <tr><td colspan="4" class="center">Nessun partecipante confermato.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Generato il {{ now()->format('d/m/Y H:i') }} — {{ $associazione?->nome }}
    </div>
</body>
</html>
