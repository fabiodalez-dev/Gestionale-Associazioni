{{-- Dati calcolati di una sezione del bilancio sociale (issue #15) --}}
@php
    $d = $bilancio->dati ?? [];
    $euro = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');
    $var = fn (string $k) => ($d['confronto']['variazioni'][$k] ?? null) === null ? '' : ' ('.(($d['confronto']['variazioni'][$k] > 0) ? '+' : '').$d['confronto']['variazioni'][$k].'% sul '.($d['confronto']['anno_precedente'] ?? '').')';
@endphp
@if ($sezione === 'ente')
        <ul class="mb-0">
            <li>{{ $d['ente']['nome'] ?? '' }}@if (! empty($d['ente']['codice_fiscale'])) — C.F. {{ $d['ente']['codice_fiscale'] }}@endif</li>
            @if (! empty($d['ente']['indirizzo']))<li>Sede legale: {{ $d['ente']['indirizzo'] }}</li>@endif
            @if (! empty($d['ente']['sedi']))<li>Sedi operative: {{ implode(', ', $d['ente']['sedi']) }}</li>@endif
        </ul>
@elseif ($sezione === 'governo')
        <ul class="mb-0">
            <li>Riunioni del direttivo: {{ $d['governo']['riunioni_direttivo'] ?? 0 }}; assemblee dei soci: {{ $d['governo']['assemblee'] ?? 0 }}@if (($d['governo']['partecipazione_assemblee'] ?? null) !== null)<span>, con una partecipazione media del {{ $d['governo']['partecipazione_assemblee'] }}% degli aventi diritto</span>@endif.</li>
            <li>Decisioni approvate: {{ $d['governo']['decisioni'] ?? 0 }}.</li>
            <li>Proposte dei soci presentate: {{ $d['governo']['proposte_presentate'] ?? 0 }}, accolte: {{ $d['governo']['proposte_accolte'] ?? 0 }}.</li>
            @if (! empty($d['governo']['cariche']))
                <li>Cariche al 31/12: {{ collect($d['governo']['cariche'])->map(fn ($c) => $c['ruolo'].' '.$c['nome'])->join('; ') }}.</li>
            @endif
        </ul>
@elseif ($sezione === 'persone')
        <ul class="mb-0">
            <li>Soci al 31/12: {{ $d['persone']['soci_fine_anno'] ?? 0 }} (nuovi {{ $d['persone']['nuovi_soci'] ?? 0 }}{{ $var('nuovi_soci') }}, cessati {{ $d['persone']['cessazioni'] ?? 0 }}).</li>
            @if (! empty($d['persone']['soci_per_tipo']))
                <li>Per tipo: {{ collect($d['persone']['soci_per_tipo'])->map(fn ($n, $t) => "{$t} {$n}")->join(', ') }}.</li>
            @endif
            <li>Volontari impegnati nei turni: {{ $d['persone']['volontari'] ?? 0 }}, per {{ number_format((float) ($d['persone']['ore_volontariato'] ?? 0), 1, ',', '.') }} ore{{ $var('ore_volontariato') }}.</li>
        </ul>
@elseif ($sezione === 'attivita')
        <ul class="mb-0">
            <li>Eventi: {{ $d['attivita']['eventi'] ?? 0 }}, con {{ $d['attivita']['partecipazioni'] ?? 0 }} partecipazioni{{ $var('partecipazioni') }}.</li>
            @foreach ($d['attivita']['progetti'] ?? [] as $progetto)
                <li>Progetto {{ $progetto['nome'] }}: {{ $progetto['stato'] }}, avanzamento {{ $progetto['avanzamento'] }}%.</li>
            @endforeach
        </ul>
@elseif ($sezione === 'economia')
        <ul class="mb-0">
            <li>Entrate {{ $euro($d['economia']['entrate'] ?? 0) }}{{ $var('entrate') }}, uscite {{ $euro($d['economia']['uscite'] ?? 0) }}{{ $var('uscite') }}, saldo {{ $euro($d['economia']['saldo'] ?? 0) }}.</li>
            <li>Donazioni ricevute: {{ $euro($d['economia']['donazioni'] ?? 0) }}{{ $var('donazioni') }}.</li>
            @if (! empty($d['economia']['entrate_per_categoria']))
                <li>Entrate per categoria: {{ collect($d['economia']['entrate_per_categoria'])->map(fn ($v, $c) => $c.' '.$euro($v))->join('; ') }}.</li>
            @endif
            @if (! empty($d['economia']['uscite_per_categoria']))
                <li>Uscite per categoria: {{ collect($d['economia']['uscite_per_categoria'])->map(fn ($v, $c) => $c.' '.$euro($v))->join('; ') }}.</li>
            @endif
        </ul>
@endif
