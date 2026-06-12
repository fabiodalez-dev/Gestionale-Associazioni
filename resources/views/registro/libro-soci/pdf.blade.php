<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Libro soci{{ $anno !== null ? ' '.$anno : '' }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #222; }
        .header { border-bottom: 3px solid #FF7B11; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; }
        .header .assoc-info { font-size: 9px; color: #666; }
        h2.anno { font-size: 13px; margin: 14px 0 6px 0; }
        table.registro { width: 100%; border-collapse: collapse; }
        table.registro th, table.registro td { border: 1px solid #ccc; padding: 4px 5px; font-size: 9px; text-align: left; vertical-align: top; }
        table.registro th { background: #f2f2f2; }
        .muted { color: #777; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $associazione?->nome ?? 'Associazione' }} — Libro soci{{ $anno !== null ? ' '.$anno : '' }}</h1>
        <div class="assoc-info">
            @if ($associazione?->indirizzo){{ $associazione->indirizzo }}@endif
            @if ($associazione?->citta) — {{ $associazione->cap }} {{ $associazione->citta }} ({{ $associazione->provincia }})@endif
            @if ($associazione?->codice_fiscale) — C.F. {{ $associazione->codice_fiscale }}@elseif ($associazione?->partita_iva) — P.IVA {{ $associazione->partita_iva }}@endif
        </div>
    </div>

    @forelse ($perAnno as $annoGruppo => $righeAnno)
        <h2 class="anno">Anno {{ $annoGruppo }} — {{ count($righeAnno) }} {{ count($righeAnno) === 1 ? 'iscrizione' : 'iscrizioni' }}</h2>
        <table class="registro">
            <thead>
                <tr>
                    <th style="width: 5%;">N.</th>
                    <th style="width: 17%;">Socio</th>
                    <th style="width: 13%;">Codice fiscale</th>
                    <th style="width: 8%;">Nascita</th>
                    <th style="width: 18%;">Indirizzo</th>
                    <th style="width: 8%;">Ammissione</th>
                    <th style="width: 10%;">Delibera</th>
                    <th style="width: 8%;">Stato</th>
                    <th style="width: 13%;">Cessazione</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($righeAnno as $riga)
                    @php
                        $socio = $riga['socio'];
                        $cessazione = $riga['cessazione'];
                    @endphp
                    <tr>
                        <td>{{ $riga['progressivo'] }}/{{ $riga['anno'] }}</td>
                        <td>
                            {{ $socio->cognome }} {{ $socio->nome }}
                            <div class="muted">Socio n. {{ $socio->numero_socio }}</div>
                        </td>
                        <td>{{ $socio->codice_fiscale ?: '—' }}</td>
                        <td>{{ $socio->data_nascita?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ trim(($socio->indirizzo ?? '').' '.($socio->cap ?? '').' '.($socio->citta ?? '').' '.($socio->provincia ?? '')) ?: '—' }}</td>
                        <td>{{ $socio->data_iscrizione?->format('d/m/Y') }}</td>
                        <td>
                            @if (filled($riga['delibera_numero']))
                                n. {{ $riga['delibera_numero'] }}@if ($riga['delibera_data']) del {{ $riga['delibera_data']->format('d/m/Y') }}@endif
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $socio->stato }}</td>
                        <td>
                            @if ($cessazione !== null)
                                {{ $cessazione->data?->format('d/m/Y') }} ({{ $cessazione->tipo }})
                                @if (filled($cessazione->motivo))<div class="muted">{{ $cessazione->motivo }}</div>@endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>Nessun socio ammesso{{ $anno !== null ? ' nell\'anno '.$anno : '' }}.</p>
    @endforelse

    <div class="footer">
        Libro soci generato il {{ now()->format('d/m/Y H:i') }} — {{ $associazione?->nome ?? 'Associazione' }}
    </div>
</body>
</html>
