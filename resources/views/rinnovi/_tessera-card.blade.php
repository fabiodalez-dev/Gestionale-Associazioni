@php
    $tessera = $pagina['tessera'];
    $socio = $pagina['socio'];
@endphp
<div class="card">
    <div class="testata">
        {!! $logoImg !!}
        <span class="associazione">{{ \Illuminate\Support\Str::limit($associazione->nome, 42) }}</span>
        <div class="titolo">Tessera associativa {{ $tessera->anno_validita }}</div>
    </div>
    <table class="corpo">
        <tr>
            <td>
                <div class="nome">{{ \Illuminate\Support\Str::limit($socio->nome_completo, 30) }}</div>
                <div class="dati">
                    Socio n. {{ $socio->numero_socio }}<br>
                    Tessera n. {{ $tessera->numero_tessera }}<br>
                    Valida fino al {{ $pagina['validoFinoAl'] }}
                    @if ($socio->tipoSocio?->nome)<br>{{ $socio->tipoSocio->nome }}@endif
                </div>
            </td>
            <td class="qr"><img src="{{ $pagina['qrImg'] }}" alt="QR" style="width: 20mm; height: 20mm;"></td>
        </tr>
    </table>
</div>
