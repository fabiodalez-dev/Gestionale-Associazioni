<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Statistiche annuali {{ $anno }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #222; }
        .header { border-bottom: 3px solid #0D9488; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 17px; margin: 0 0 4px 0; }
        .header .assoc-info { font-size: 9px; color: #666; }
        .intro { margin: 0 0 14px 0; color: #555; }
        table.cifre { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.cifre th, table.cifre td { border: 1px solid #ccc; padding: 6px 8px; font-size: 10px; text-align: left; }
        table.cifre th { background: #f2f2f2; }
        table.cifre td.num { text-align: right; }
        .pos { color: #198754; }
        .neg { color: #dc3545; }
        .muted { color: #888; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #999; text-align: center; }
    </style>
</head>
<body>
@php
    use Illuminate\Support\Str;
    $righe = [
        'nuovi_soci' => ['Nuovi soci', false, false],
        'cessazioni' => ['Cessazioni', false, true],
        'retention' => ['Retention %', true, false],
        'tessere' => ['Tessere emesse', false, false],
        'partecipazioni' => ['Partecipazioni eventi', false, false],
        'ore_volontariato' => ['Ore volontariato', true, false],
        'entrate' => ['Entrate (€)', true, false],
        'uscite' => ['Uscite (€)', true, true],
        'saldo' => ['Saldo (€)', true, false],
        'donazioni' => ['Donazioni (€)', true, false],
    ];
    $fmt = function ($k, $v) {
        if (in_array($k, ['entrate','uscite','saldo','donazioni'], true)) {
            return '€ '.number_format((float) $v, 2, ',', '.');
        }
        if ($k === 'retention') return number_format((float) $v, 1, ',', '.');
        if ($k === 'ore_volontariato') return number_format((float) $v, 2, ',', '.');
        return (int) $v;
    };
@endphp
    <div class="header">
        <h1>{{ $associazione?->nome ?? 'Associazione' }} — Anno in cifre {{ $anno }}</h1>
        <div class="assoc-info">
            @if ($associazione?->indirizzo){{ $associazione->indirizzo }}@endif
            @if ($associazione?->citta) — {{ $associazione->cap }} {{ $associazione->citta }} ({{ $associazione->provincia }})@endif
            @if ($associazione?->codice_fiscale) — C.F. {{ $associazione->codice_fiscale }}@endif
        </div>
    </div>

    <p class="intro">Riepilogo delle attività dell'anno {{ $anno }} a confronto con l'anno {{ $dati['anno_precedente'] }},
        per la presentazione in assemblea.</p>

    <table class="cifre">
        <thead>
            <tr>
                <th style="width: 40%;">Indicatore</th>
                <th style="width: 20%;" class="num">{{ $dati['anno_precedente'] }}</th>
                <th style="width: 20%;" class="num">{{ $anno }}</th>
                <th style="width: 20%;" class="num">Variazione</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($righe as $chiave => [$label, $decimale, $negativoMeglio])
                @php
                    $valore = $dati['corrente'][$chiave];
                    $prec = $dati['precedente'][$chiave];
                    $var = $dati['variazioni'][$chiave];
                    $classe = 'muted';
                    if ($var !== null && abs($var) > 0.0001) {
                        $buono = $negativoMeglio ? ($var < 0) : ($var > 0);
                        $classe = $buono ? 'pos' : 'neg';
                    }
                @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td class="num">{{ $fmt($chiave, $prec) }}</td>
                    <td class="num">{{ $fmt($chiave, $valore) }}</td>
                    <td class="num {{ $classe }}">
                        @if ($var === null)
                            —
                        @else
                            {{ $var >= 0 ? '+' : '' }}{{ number_format($var, 1, ',', '.') }}%
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generato il {{ now()->format('d/m/Y H:i') }} — {{ $associazione?->nome ?? '' }}
    </div>
</body>
</html>
