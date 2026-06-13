<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Attestato volontariato {{ $anno }} — {{ $socio->nome_completo }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 12px; color: #222; }
        .header { border-bottom: 3px solid #FF7B11; padding-bottom: 10px; margin-bottom: 24px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; }
        .header .assoc-info { font-size: 10px; color: #666; }
        h2.titolo { font-size: 18px; text-align: center; margin: 10px 0 18px 0; }
        .intro { font-size: 12px; line-height: 1.6; margin-bottom: 16px; }
        .totale { font-size: 14px; font-weight: bold; margin: 14px 0; padding: 8px; border: 1px solid #FF7B11; background: #fff6ef; text-align: center; }
        table.voci { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.voci th, table.voci td { border: 1px solid #ccc; padding: 5px 8px; font-size: 11px; text-align: left; }
        table.voci th { background: #f2f2f2; }
        table.voci td.ore { text-align: right; }
        .riga-firma { margin-top: 50px; }
        .riga-firma .linea { display: inline-block; width: 240px; border-top: 1px solid #333; padding-top: 3px; font-size: 10px; text-align: center; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $associazione?->nome ?? 'Associazione' }}</h1>
        <div class="assoc-info">
            @if ($associazione?->indirizzo){{ $associazione->indirizzo }}@endif
            @if ($associazione?->citta) — {{ $associazione->cap }} {{ $associazione->citta }} ({{ $associazione->provincia }})@endif
            @if ($associazione?->codice_fiscale) — C.F. {{ $associazione->codice_fiscale }}@elseif ($associazione?->partita_iva) — P.IVA {{ $associazione->partita_iva }}@endif
        </div>
    </div>

    <h2 class="titolo">Attestato di attività di volontariato — Anno {{ $anno }}</h2>

    <div class="intro">
        Si attesta che <strong>{{ $socio->nome_completo }}</strong>{{ $socio->codice_fiscale ? ' (C.F. '.$socio->codice_fiscale.')' : '' }}{{ $socio->numero_socio ? ', socio n. '.$socio->numero_socio : '' }}
        ha svolto attività di volontariato presso <strong>{{ $associazione?->nome ?? 'l\'associazione' }}</strong>
        nel corso dell'anno {{ $anno }}, per il totale di ore di seguito riportato.
    </div>

    <div class="totale">Totale ore di volontariato {{ $anno }}: {{ rtrim(rtrim(number_format($totale, 2, ',', '.'), '0'), ',') }} ore</div>

    @if ($voci->isNotEmpty())
        <table class="voci">
            <thead>
                <tr><th>Data</th><th>Attività</th><th>Mansione</th><th class="ore">Ore</th></tr>
            </thead>
            <tbody>
                @foreach ($voci as $v)
                    <tr>
                        <td>{{ $v->turno?->data?->format('d/m/Y') }}</td>
                        <td>{{ $v->turno?->titolo }}</td>
                        <td>{{ $v->turno?->mansione?->nome ?? '—' }}</td>
                        <td class="ore">{{ $v->ore_effettive }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="riga-firma">
        Luogo e data: ____________________________
        <br><br><br>
        <span class="linea">Il legale rappresentante</span>
    </div>

    <div class="footer">Documento generato da {{ config('app.name') }} il {{ now()->format('d/m/Y') }}</div>
</body>
</html>
