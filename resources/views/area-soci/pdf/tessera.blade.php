<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Tessera {{ $tessera->numero_tessera }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; background: #ffffff; }
        .card {
            width: 100%;
            border: 2px solid #FF7B11;
            border-radius: 12px;
            padding: 24px;
        }
        .header { border-bottom: 2px solid #FF7B11; padding-bottom: 12px; margin-bottom: 18px; }
        .assoc { font-size: 20px; font-weight: bold; color: #FF7B11; }
        .label { font-size: 9px; color: #6B7280; text-transform: uppercase; letter-spacing: 1px; }
        .value { font-size: 14px; font-weight: bold; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .qr { text-align: right; }
        .qr img { width: 130px; height: 130px; }
        .footer { margin-top: 14px; font-size: 9px; color: #9CA3AF; }
        .stato { display: inline-block; background: #FF7B11; color: #fff; border-radius: 6px; padding: 3px 10px; font-size: 11px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <span class="assoc">{{ $associazione?->nome ?? 'Associazione' }}</span>
            <div class="label">Tessera socio</div>
        </div>

        <table>
            <tr>
                <td width="65%">
                    <div class="label">Socio</div>
                    <div class="value">{{ $socio->nome_completo }}</div>

                    <div class="label">Numero socio</div>
                    <div class="value">{{ $socio->numero_socio }}</div>

                    <div class="label">Numero tessera</div>
                    <div class="value">{{ $tessera->numero_tessera }}</div>

                    <table>
                        <tr>
                            <td width="33%">
                                <div class="label">Anno</div>
                                <div class="value">{{ $tessera->anno_validita }}</div>
                            </td>
                            <td width="33%">
                                <div class="label">Emissione</div>
                                <div class="value">{{ $tessera->data_emissione?->format('d/m/Y') }}</div>
                            </td>
                            <td width="33%">
                                <div class="label">Scadenza</div>
                                <div class="value">{{ $tessera->data_scadenza?->format('d/m/Y') }}</div>
                            </td>
                        </tr>
                    </table>

                    <span class="stato">{{ $statoCalcolato }}</span>
                </td>
                <td width="35%" class="qr">
                    @if ($qrPng !== null)
                        <img src="{{ $qrPng }}" alt="QR code">
                    @endif
                </td>
            </tr>
        </table>

        <div class="footer">
            Documento generato il {{ now()->format('d/m/Y H:i') }} — {{ $associazione?->nome }}
        </div>
    </div>
</body>
</html>
