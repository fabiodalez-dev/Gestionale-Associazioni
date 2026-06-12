{{-- Contatti rapidi del socio (f.10 piano Gestione 2): bottoni
     click-to-chat/chiama/email — pura UI, zero API.
     Variabili: $socio; opzionale $dimensione ('sm' default). --}}
@php
    $dimensione ??= 'sm';
    $waUrl = \App\Services\Comunicazione\ContattiRapidi::whatsappUrl($socio->telefono);
    $telUrl = \App\Services\Comunicazione\ContattiRapidi::telUrl($socio->telefono);
@endphp
<span class="btn-group{{ $dimensione === 'sm' ? ' btn-group-sm' : '' }}" role="group" aria-label="Contatti rapidi">
    @if (filled($socio->email))
        <a href="mailto:{{ $socio->email }}" class="btn btn-outline-secondary" title="Scrivi una email a {{ $socio->email }}">
            <i class="bi bi-envelope"></i>
        </a>
    @endif
    @if ($telUrl !== null)
        <a href="{{ $telUrl }}" class="btn btn-outline-secondary" title="Chiama {{ $socio->telefono }}">
            <i class="bi bi-telephone"></i>
        </a>
    @endif
    @if ($waUrl !== null)
        <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="btn btn-outline-success" title="Scrivi su WhatsApp a {{ $socio->telefono }}">
            <i class="bi bi-whatsapp"></i>
        </a>
    @endif
</span>
