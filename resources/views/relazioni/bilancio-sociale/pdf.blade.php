<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Bilancio sociale {{ $bilancio->anno }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #222; line-height: 1.45; }
        .copertina { text-align: center; margin-top: 180px; }
        .copertina h1 { font-size: 26px; margin: 0 0 8px 0; }
        .copertina .anno { font-size: 40px; color: #0D9488; font-weight: bold; }
        .copertina .info { color: #666; margin-top: 24px; }
        .pagina { page-break-before: always; }
        h2 { font-size: 15px; border-bottom: 2px solid #0D9488; padding-bottom: 4px; margin: 22px 0 8px 0; }
        .dati { background: #f4f6f6; border: 1px solid #dde3e3; padding: 8px 10px; margin-bottom: 10px; font-size: 10px; }
        .dati ul { margin: 0; padding-left: 16px; }
        .testo { white-space: pre-line; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="copertina">
        <h1>{{ $associazione->nome }}</h1>
        <div>Bilancio sociale</div>
        <div class="anno">{{ $bilancio->anno }}</div>
        <div class="info">
            @if ($bilancio->verbale)
                Approvato dall'assemblea dei soci il {{ $bilancio->verbale->data_riunione?->format('d/m/Y') }}
            @elseif ($bilancio->stato === 'bozza')
                Bozza
            @endif
        </div>
    </div>

    <div class="pagina">
        @foreach (\App\Models\BilancioSociale::SEZIONI as $chiave => $sezione)
            <h2>{{ $loop->iteration }}. {{ $sezione['titolo'] }}</h2>
            @if (in_array($chiave, ['ente', 'governo', 'persone', 'attivita', 'economia'], true))
                <div class="dati">@include('relazioni.bilancio-sociale._dati', ['bilancio' => $bilancio, 'sezione' => $chiave])</div>
            @endif
            @if ($bilancio->testo($chiave) !== '')
                <div class="testo">{{ $bilancio->testo($chiave) }}</div>
            @endif
        @endforeach
    </div>

    <div class="footer">{{ $associazione->nome }} — Bilancio sociale {{ $bilancio->anno }}</div>
</body>
</html>
