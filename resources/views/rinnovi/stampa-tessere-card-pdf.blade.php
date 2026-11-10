<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Tessere — {{ $associazione->nome }}</title>
    {{-- Tessere in formato carta di credito (#47): una per pagina CR80, oppure 10 per foglio A4 con linee di taglio --}}
    <style>
        @if ($formato === 'cr80')
            @page { margin: 0; }
        @else
            @page { size: A4; margin: 13.5mm 19.4mm; }
        @endif
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #222; margin: 0; }
        /* dompdf non applica box-sizing: border-box, quindi il padding si somma:
           85,6 × 54 mm = contenuto 77,6 × 47 mm + padding 4 × 3,5 mm */
        .card { width: 77.6mm; height: {{ $formato === 'cr80' ? '46.4mm' : '46.5mm' }}; padding: 3.5mm 4mm; overflow: hidden; }
        .card .testata { border-bottom: 0.6mm solid #003366; padding-bottom: 1mm; margin-bottom: 1.5mm; }
        .card .testata img { height: 7mm; max-height: 7mm; width: auto; vertical-align: middle; }
        .card .associazione { font-weight: bold; color: #003366; font-size: 7.5pt; vertical-align: middle; }
        .card .titolo { font-size: 6pt; color: #555; }
        .card table.corpo { width: 100%; border-collapse: collapse; }
        .card table.corpo td { border: 0; padding: 0; vertical-align: top; }
        .card .nome { font-size: 9.5pt; font-weight: bold; margin-bottom: 1mm; }
        .card .dati { font-size: 6.5pt; line-height: 1.45; }
        .card td.qr { width: 21mm; text-align: right; }
        /* Foglio A4: griglia 2 × 5 a posizioni fisse (le righe di tabella
           vicino al fondo pagina dompdf le manda alla pagina dopo) */
        .foglio { position: relative; height: 270mm; }
        .cella { position: absolute; width: 85.6mm; height: 54mm; border: 0.15mm dashed #999; }
        /* Interruzione prima, non dopo: un contenuto che riempie la pagina
           seguito da un'interruzione produrrebbe pagine bianche */
        .pagina-cr80 + .pagina-cr80, .foglio + .foglio { page-break-before: always; }
        table.griglia { border-collapse: collapse; }
        table.griglia { table-layout: fixed; }
        table.griglia td { padding: 0; border: 0.2mm dashed #999; height: 53mm; overflow: hidden; vertical-align: top; }
    </style>
</head>
<body>
    @php
        $tesserina = function (array $pagina) use ($associazione, $logoImg) {
            return view('rinnovi._tessera-card', ['pagina' => $pagina, 'associazione' => $associazione, 'logoImg' => $logoImg])->render();
        };
    @endphp

    @if ($formato === 'cr80')
        @foreach ($pagine as $pagina)
            <div class="pagina-cr80">{!! $tesserina($pagina) !!}</div>
        @endforeach
    @else
        @foreach ($fogli as $foglio)
            <div class="foglio">
                @foreach ($foglio->values() as $i => $pagina)
                    <div class="cella" style="left: {{ ($i % 2) * 85.6 }}mm; top: {{ intdiv($i, 2) * 54 }}mm;">{!! $tesserina($pagina) !!}</div>
                @endforeach
            </div>
        @endforeach
    @endif
</body>
</html>
