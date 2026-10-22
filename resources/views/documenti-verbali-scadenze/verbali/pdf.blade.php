<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>{{ $verbale->titolo }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 12px; color: #222; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 10px; margin-bottom: 20px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; }
        .header .assoc-info { font-size: 10px; color: #666; }
        h2.titolo { font-size: 15px; margin: 0 0 6px 0; }
        .meta { font-size: 11px; color: #555; margin-bottom: 16px; }
        .badge { display: inline-block; padding: 2px 6px; border: 1px solid #999; border-radius: 3px; font-size: 10px; }
        .sezione { margin-bottom: 14px; }
        .sezione h3 { font-size: 12px; border-bottom: 1px solid #ddd; padding-bottom: 3px; margin-bottom: 6px; }
        .sezione .testo { white-space: pre-wrap; }
        .approvazione { margin-top: 18px; padding: 8px; border: 1px solid #ccc; background: #f8f8f8; font-size: 11px; }
        table.firme { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.firme th, table.firme td { border: 1px solid #ccc; padding: 6px 8px; font-size: 11px; text-align: left; }
        table.firme th { background: #f2f2f2; }
        .riga-firma { margin-top: 36px; }
        .riga-firma .linea { display: inline-block; width: 220px; border-top: 1px solid #333; margin-right: 30px; padding-top: 3px; font-size: 10px; text-align: center; }
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

    <h2 class="titolo">{{ $verbale->titolo }}</h2>
    <div class="meta">
        @if ($verbale->numero_completo)
            <span class="badge">Verbale n. {{ $verbale->numero_completo }}</span>
            &nbsp;
        @endif
        <span class="badge">{{ $verbale->tipo_riunione }}</span>
        &nbsp; Data riunione: <strong>{{ $verbale->data_riunione->format('d/m/Y') }}</strong>
        @if ($verbale->evento)
            &nbsp; Evento collegato: <strong>{{ $verbale->evento->titolo }}</strong>@if ($verbale->evento->data_evento) ({{ $verbale->evento->data_evento->format('d/m/Y') }})@endif
        @endif
    </div>

    @if ($verbale->presenti)
        <div class="sezione">
            <h3>Presenti</h3>
            <div class="testo">{{ $verbale->presenti }}</div>
        </div>
    @endif

    @if ($verbale->ordine_del_giorno)
        <div class="sezione">
            <h3>Ordine del Giorno</h3>
            <div class="testo">{{ $verbale->ordine_del_giorno }}</div>
        </div>
    @endif

    @if ($verbale->deliberazioni)
        <div class="sezione">
            <h3>Deliberazioni</h3>
            <div class="testo">{{ $verbale->deliberazioni }}</div>
        </div>
    @endif

    @if ($verbale->approvato)
        <div class="approvazione">
            <strong>Verbale approvato</strong>
            da {{ $verbale->approvatoDa?->nome_completo ?? 'N/D' }}
            @if ($verbale->data_approvazione) in data {{ $verbale->data_approvazione->format('d/m/Y') }} @endif
        </div>
    @endif

    @if ($verbale->firmatari->isNotEmpty())
        <div class="sezione" style="margin-top: 18px;">
            <h3>Firmatari</h3>
            <table class="firme">
                <thead>
                    <tr>
                        <th>Nominativo</th>
                        <th>Ruolo</th>
                        <th>Firma</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($verbale->firmatari as $firmatario)
                        <tr>
                            <td>{{ $firmatario->nominativo }}</td>
                            <td>{{ $firmatario->ruolo ?: '—' }}</td>
                            <td>
                                @if ($firmatario->firmato_at)
                                    Firmato il {{ $firmatario->firmato_at->format('d/m/Y H:i') }}
                                @else
                                    &nbsp;
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="riga-firma">
            <span class="linea">Il Presidente</span>
            <span class="linea">Il Segretario</span>
        </div>
    @endif

    <div class="footer">
        Documento generato il {{ now()->format('d/m/Y H:i') }} — {{ $associazione?->nome ?? config('app.name') }}
    </div>
</body>
</html>
