@php
    $etichetta = fn (string $chiave) => \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $chiave));
    $valore = function (mixed $v): string {
        if (is_bool($v)) {
            return $v ? 'Sì' : 'No';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) ($v ?? '—');
    };
    $sezioni = collect($dati)->except(['esportato_at', 'associazione']);
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dati personali</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #222; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; }
        .muted { color: #777; }
        h2 { font-size: 12px; margin: 16px 0 6px 0; }
        h3 { font-size: 10px; margin: 10px 0 4px 0; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { border: 1px solid #ccc; padding: 3px 5px; font-size: 9px; text-align: left; vertical-align: top; word-break: break-word; }
        th { background: #f2f2f2; width: 32%; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Dati personali di {{ $titolare->nome }} {{ $titolare->cognome }}</h1>
        <div class="muted">{{ $dati['associazione'] ?? '' }} — esportati il {{ \Illuminate\Support\Carbon::parse($dati['esportato_at'])->format('d/m/Y H:i') }}. I dati completi in formato leggibile da macchina sono nel file dati.json.</div>
    </div>

    @foreach ($sezioni as $nome => $contenuto)
        <h2>{{ $etichetta($nome) }}</h2>
        @if (blank($contenuto))
            <p class="muted">Nessun dato.</p>
        @elseif (is_array($contenuto) && array_is_list($contenuto))
            @foreach ($contenuto as $i => $riga)
                @if (is_array($riga))
                    <h3>{{ $i + 1 }}</h3>
                    <table>
                        @foreach ($riga as $chiave => $v)
                            <tr><th>{{ $etichetta((string) $chiave) }}</th><td>{{ $valore($v) }}</td></tr>
                        @endforeach
                    </table>
                @else
                    <p>{{ $valore($riga) }}</p>
                @endif
            @endforeach
        @elseif (is_array($contenuto))
            <table>
                @foreach ($contenuto as $chiave => $v)
                    <tr><th>{{ $etichetta((string) $chiave) }}</th><td>{{ $valore($v) }}</td></tr>
                @endforeach
            </table>
        @else
            <p>{{ $valore($contenuto) }}</p>
        @endif
    @endforeach
</body>
</html>
