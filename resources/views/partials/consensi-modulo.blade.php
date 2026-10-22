{{-- Consensi aggiuntivi configurati dall'associazione (ModuliSettings), campi consensi[chiave]. --}}
@foreach ($associazione->moduli()->elencoConsensi() as $consenso)
    @php
        $idConsenso = 'consenso_'.$consenso['chiave'];
        $erroreConsenso = $errors->first('consensi.'.$consenso['chiave']);
    @endphp
    <div class="form-check mb-2">
        <input class="form-check-input @if ($erroreConsenso) is-invalid @endif" type="checkbox" id="{{ $idConsenso }}"
               name="consensi[{{ $consenso['chiave'] }}]" value="1" @checked(old('consensi.'.$consenso['chiave'])) @required($consenso['obbligatorio'])>
        <label class="form-check-label" for="{{ $idConsenso }}">
            {{ $consenso['testo'] }}
            @if ($consenso['link_url'])
                <a href="{{ $consenso['link_url'] }}" target="_blank" rel="noopener">— dettagli</a>
            @endif
            @if ($consenso['obbligatorio'])<span class="text-danger ms-1" aria-hidden="true">*</span>@else<span class="text-muted small ms-1">(facoltativo)</span>@endif
        </label>
        @if ($erroreConsenso)<div class="invalid-feedback d-block">{{ $erroreConsenso }}</div>@endif
    </div>
@endforeach
