<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Biglietto {{ $prenotazione->codice }}</title>
    <style>
        @page { size: A4; margin: 18mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #222; font-size: 12pt; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 10px; margin-bottom: 20px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .brand-wrap img { display: block; height: 48px; width: auto; }
        .brand { font-weight: bold; color: #0D9488; font-size: 16pt; }
        .doc-title { font-size: 11pt; color: #666; margin-top: 2px; text-transform: uppercase; letter-spacing: 1px; }
        .evento-titolo { font-size: 20pt; font-weight: bold; margin: 0 0 6px 0; }
        .evento-meta { font-size: 12pt; color: #444; margin-bottom: 18px; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .grid td { padding: 7px 10px; vertical-align: top; border-bottom: 1px solid #eee; }
        .label { color: #444; font-weight: bold; width: 35%; }
        .codice { font-size: 16pt; font-weight: bold; letter-spacing: 2px; }
        .qr-block { text-align: center; margin-top: 22px; }
        .qr-block svg { width: 220px; height: 220px; }
        .qr-caption { font-size: 10pt; color: #666; margin-top: 8px; }
        .box { border: 2px dashed #0D9488; border-radius: 8px; padding: 16px; margin-top: 10px; }
        .note { font-size: 9pt; color: #777; margin-top: 22px; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                @if ($logoImg !== '')
                    <td class="brand-wrap" style="width:60px;">{!! $logoImg !!}</td>
                @endif
                <td>
                    <div class="brand">{{ $associazione->nome }}</div>
                    <div class="doc-title">Biglietto d'ingresso</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="evento-titolo">{{ $evento->titolo }}</div>
    <div class="evento-meta">
        {{ $evento->data_evento?->format('d/m/Y H:i') }}
        @if ($evento->data_fine)
            &ndash; {{ $evento->data_fine->format('d/m/Y H:i') }}
        @endif
        @if ($evento->luogo)
            &middot; {{ $evento->luogo }}
        @endif
    </div>

    <div class="box">
        <table class="grid">
            <tr>
                <td class="label">Intestatario</td>
                <td>{{ trim($account->nome.' '.$account->cognome) }}</td>
            </tr>
            <tr>
                <td class="label">Posti</td>
                <td>{{ $prenotazione->posti }}</td>
            </tr>
            <tr>
                <td class="label">Codice prenotazione</td>
                <td class="codice">{{ $prenotazione->codice }}</td>
            </tr>
            @if ((float) $prenotazione->importo_totale > 0)
                <tr>
                    <td class="label">Importo</td>
                    <td>&euro; {{ number_format((float) $prenotazione->importo_totale, 2, ',', '.') }}</td>
                </tr>
            @endif
        </table>

        <div class="qr-block">
            {!! $qrSvg !!}
            <div class="qr-caption">Presenta questo QR all'ingresso per il check-in</div>
        </div>
    </div>

    <div class="note">
        Biglietto valido per una sola entrata ({{ $prenotazione->posti }} {{ (int) $prenotazione->posti === 1 ? 'posto' : 'posti' }}).
        Il QR code è personale e verificabile in tempo reale: la cessione o la duplicazione non danno diritto all'ingresso.
        &mdash; {{ $associazione->nome }}
    </div>
</body>
</html>
