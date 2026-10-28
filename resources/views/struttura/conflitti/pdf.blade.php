<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Conflitti di interesse {{ $anno }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #222; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; }
        h2 { font-size: 13px; margin: 16px 0 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 5px; font-size: 9px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .muted { color: #777; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $associazione?->nome ?? 'Associazione' }} — Registro dei conflitti di interesse {{ $anno }}</h1>
        <div class="muted">Dichiarazioni dei titolari di cariche e astensioni per conflitto nelle riunioni.</div>
    </div>

    <h2>Dichiarazioni annuali</h2>
    <table>
        <thead><tr><th style="width: 22%">Titolare</th><th style="width: 18%">Cariche</th><th style="width: 12%">Data</th><th>Dichiarazione</th></tr></thead>
        <tbody>
            @forelse ($titolari as $socio)
                @php $dichiarazione = $dichiarazioni->get($socio->id); @endphp
                <tr>
                    <td>{{ $socio->nome_completo }}</td>
                    <td>{{ $socio->cariche->pluck('ruolo')->unique()->join(', ') }}</td>
                    <td>{{ $dichiarazione?->dichiarata_at?->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        @if (! $dichiarazione)
                            Non presentata
                        @elseif ($dichiarazione->nessun_conflitto)
                            Nessun conflitto di interesse
                        @else
                            {!! nl2br(e($dichiarazione->interessi)) !!}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nessuna carica nell'anno.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Conflitti dichiarati nelle riunioni</h2>
    <table>
        <thead><tr><th style="width: 12%">Data</th><th style="width: 26%">Riunione</th><th style="width: 26%">Punto</th><th style="width: 18%">Socio</th><th>Motivazione</th></tr></thead>
        <tbody>
            @forelse ($conflitti as $conflitto)
                @php $punto = $conflitto->riunione?->odg[$conflitto->punto_indice] ?? null; @endphp
                <tr>
                    <td>{{ $conflitto->riunione?->data_ora?->format('d/m/Y') }}</td>
                    <td>{{ $conflitto->riunione?->titolo }}</td>
                    <td>{{ $conflitto->punto_indice + 1 }}. {{ is_array($punto) ? ($punto['punto'] ?? '') : $punto }}</td>
                    <td>{{ $conflitto->socio?->nome_completo }}</td>
                    <td>{{ $conflitto->motivazione ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nessun conflitto dichiarato.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">Generato il {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
