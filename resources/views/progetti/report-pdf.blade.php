<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Report progetto — {{ $progetto->nome }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #222; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 17px; margin: 0 0 4px 0; }
        .header .assoc-info { font-size: 9px; color: #666; }
        h2.sezione { font-size: 13px; margin: 18px 0 6px 0; border-bottom: 1px solid #ddd; padding-bottom: 3px; color: #0D9488; }
        table { width: 100%; border-collapse: collapse; }
        table.dati td { padding: 3px 5px; font-size: 10px; vertical-align: top; }
        table.dati td.label { color: #666; width: 28%; }
        table.griglia th, table.griglia td { border: 1px solid #ccc; padding: 4px 6px; font-size: 10px; text-align: left; }
        table.griglia th { background: #f2f2f2; }
        .text-end { text-align: right; }
        .muted { color: #777; }
        .totali td { font-weight: bold; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Report progetto — {{ $progetto->nome }}</h1>
        <div class="assoc-info">
            {{ $associazione?->nome ?? 'Associazione' }}
            @if ($associazione?->indirizzo) — {{ $associazione->indirizzo }}@endif
            @if ($associazione?->citta) — {{ $associazione->cap }} {{ $associazione->citta }} ({{ $associazione->provincia }})@endif
            @if ($associazione?->codice_fiscale) — C.F. {{ $associazione->codice_fiscale }}@elseif ($associazione?->partita_iva) — P.IVA {{ $associazione->partita_iva }}@endif
        </div>
    </div>

    {{-- Anagrafica --}}
    <h2 class="sezione">Anagrafica progetto</h2>
    <table class="dati">
        <tr><td class="label">Stato</td><td>{{ ucfirst($progetto->stato) }}</td></tr>
        <tr><td class="label">Periodo</td><td>{{ $progetto->dal?->format('d/m/Y') ?? '—' }} → {{ $progetto->al?->format('d/m/Y') ?? '—' }}</td></tr>
        <tr><td class="label">Responsabile</td><td>{{ $progetto->responsabile ? trim($progetto->responsabile->cognome.' '.$progetto->responsabile->nome) ?: $progetto->responsabile->email : '—' }}</td></tr>
        <tr><td class="label">Budget</td><td>{{ $progetto->budget !== null ? '€ '.number_format((float) $progetto->budget, 2, ',', '.') : '—' }}</td></tr>
        @if (filled($progetto->descrizione))
            <tr><td class="label">Descrizione</td><td>{{ $progetto->descrizione }}</td></tr>
        @endif
        <tr><td class="label">Avanzamento</td><td>{{ $progetto->percentualeAvanzamento() }}% milestone completate</td></tr>
    </table>

    {{-- Rendiconto economico --}}
    <h2 class="sezione">Rendiconto economico</h2>
    <table class="griglia">
        <thead><tr><th>Voce</th><th class="text-end">Importo</th></tr></thead>
        <tbody>
            <tr><td>Entrate</td><td class="text-end">&euro; {{ number_format($rendiconto['entrate'], 2, ',', '.') }}</td></tr>
            <tr><td>Uscite</td><td class="text-end">&euro; {{ number_format($rendiconto['uscite'], 2, ',', '.') }}</td></tr>
            <tr class="totali"><td>Saldo</td><td class="text-end">&euro; {{ number_format($rendiconto['saldo'], 2, ',', '.') }}</td></tr>
            @if ($rendiconto['budget'] !== null)
                <tr><td>Budget</td><td class="text-end">&euro; {{ number_format($rendiconto['budget'], 2, ',', '.') }}</td></tr>
                <tr><td>Scostamento (budget − uscite)</td><td class="text-end">&euro; {{ number_format($rendiconto['scostamento'] ?? 0, 2, ',', '.') }}</td></tr>
            @endif
        </tbody>
    </table>
    @if ($rendiconto['perCategoria']->isNotEmpty())
        <table class="griglia" style="margin-top: 8px;">
            <thead><tr><th>Categoria</th><th>Tipo</th><th class="text-end">Totale</th></tr></thead>
            <tbody>
                @foreach ($rendiconto['perCategoria'] as $riga)
                    <tr>
                        <td>{{ $riga['categoria'] }}</td>
                        <td>{{ ucfirst($riga['tipo']) }}</td>
                        <td class="text-end">&euro; {{ number_format($riga['totale'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Eventi svolti --}}
    <h2 class="sezione">Eventi collegati</h2>
    @if ($progetto->eventi->isNotEmpty())
        <table class="griglia">
            <thead><tr><th>Evento</th><th>Data</th><th class="text-end">Partecipanti confermati</th></tr></thead>
            <tbody>
                @foreach ($progetto->eventi as $evento)
                    <tr>
                        <td>{{ $evento->titolo }}</td>
                        <td>{{ $evento->data_evento?->format('d/m/Y') ?? '—' }}</td>
                        <td class="text-end">{{ (int) ($partecipantiPerEvento[$evento->id] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="muted">Nessun evento collegato.</p>
    @endif

    {{-- Ore volontariato --}}
    <h2 class="sezione">Ore di volontariato</h2>
    <p>Totale ore registrate sui turni collegati: <strong>{{ number_format($oreVolontariato['totale'], 2, ',', '.') }}</strong></p>
    @if ($oreVolontariato['perSocio']->isNotEmpty())
        <table class="griglia">
            <thead><tr><th>Socio</th><th class="text-end">Ore</th></tr></thead>
            <tbody>
                @foreach ($oreVolontariato['perSocio'] as $riga)
                    <tr><td>{{ $riga['socio'] }}</td><td class="text-end">{{ number_format($riga['ore'], 2, ',', '.') }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="muted">Nessuna ora di volontariato registrata.</p>
    @endif

    <div class="footer">
        Report generato il {{ now()->format('d/m/Y H:i') }} — {{ $associazione?->nome ?? 'Associazione' }}
    </div>
</body>
</html>
