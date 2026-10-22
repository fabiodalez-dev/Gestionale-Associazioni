<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Tessera {{ $socio->nome_completo }}</title>
    <style>
        @page { size: A4; margin: 18mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #222; font-size: 12pt; }
        .header { border-bottom: 2px solid #003366; padding-bottom: 8px; margin-bottom: 18px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .brand-wrap img { display: block; height: 48px; width: auto; }
        .brand { font-weight: bold; color: #003366; font-size: 16pt; }
        .doc-title { font-size: 14pt; margin-top: 2px; }
        .meta { font-size: 10pt; color: #666; text-align: right; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .grid td { padding: 6px 8px; vertical-align: top; }
        .label { color: #444; font-weight: bold; width: 35%; }
        .box { border: 1px solid #ccc; border-radius: 6px; padding: 10px; background: #fafafa; }
        .signatures { margin-top: 26px; display: table; width: 100%; }
        .sig { display: table-cell; width: 50%; padding-right: 12px; }
        .line { height: 1px; background: #555; margin-top: 36px; }
        .caption { text-align: center; font-size: 10pt; color: #555; margin-top: 6px; }
        .note { font-size: 10pt; color: #666; margin-top: 14px; }
        .qr-block { text-align: center; }
        .qr-block svg { width: 90px; height: 90px; }
        .qr-caption { font-size: 8pt; color: #666; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td class="brand-wrap" style="width:60px;">{!! $logoImg !!}</td>
                <td>
                    <div class="brand">{{ $associazione->nome }}</div>
                    <div class="doc-title">Tessera Associativa - {{ $tessera->anno_validita }}</div>
                </td>
                <td class="meta">Data: {{ $dataOggi }}</td>
            </tr>
        </table>
    </div>

    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="vertical-align:top;">
                <table class="grid">
                    <tr><td class="label">Nome e Cognome</td><td>{{ $socio->nome_completo }}</td></tr>
                    <tr><td class="label">Numero Socio</td><td>{{ $socio->numero_socio }}</td></tr>
                    <tr><td class="label">Categoria / Tipo</td><td>{{ trim(($socio->categoriaSocio?->nome ?? '').($socio->tipoSocio?->nome ? ' - '.$socio->tipoSocio->nome : '')) }}</td></tr>
                    <tr><td class="label">Numero Tessera</td><td>{{ $tessera->numero_tessera }}</td></tr>
                    @if ($costoStr !== '')
                    <tr><td class="label">Costo Tessera</td><td>{{ $costoStr }}</td></tr>
                    @endif
                </table>
            </td>
            @if (($fotoImg ?? '') !== '')
            <td style="width:100px; vertical-align:top; text-align:center; padding-top:6px;">{!! $fotoImg !!}</td>
            @endif
            <td style="width:120px; vertical-align:top; text-align:center; padding-top:6px;">
                <div class="qr-block">
                    {!! $qrSvg !!}
                    <div class="qr-caption">Scansiona per verificare</div>
                </div>
            </td>
        </tr>
    </table>

    @if ($testoTessera !== '')
        {{-- Testo già escapato e con newline → <br> dal renderer --}}
        <div class="box">{!! $testoTessera !!}</div>
    @endif

    <div class="signatures">
        <div class="sig">
            <div class="line"></div>
            <div class="caption">Firma del socio</div>
        </div>
        <div class="sig">
            <div class="line"></div>
            <div class="caption">Firma del Presidente/Segretario</div>
        </div>
    </div>

    <div class="note">Valida fino al {{ $validoFinoAl }}. Il presente documento, se firmato, vale come tessera associativa.</div>
</body>
</html>
