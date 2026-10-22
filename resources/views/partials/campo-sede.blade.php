{{--
    Campo sede (Fase 8). Parametri: $idCampo, $valore (sede_id corrente),
    $vuoto (etichetta dell'opzione senza sede, es. "Tutta l'associazione").
    Le sedi sono già filtrate dal perimetro dell'utente; chi è limitato a
    delle sedi non vede l'opzione vuota. Nascosto se non ci sono sedi.
--}}
@php
    $sediCampo = \App\Models\Sede::query()->orderBy('nome')->get(['id', 'nome']);
    $limitatoCampo = \App\Support\PerimetroSedi::limitato();
@endphp
@if ($sediCampo->isNotEmpty())
    <div class="mb-3">
        <label for="{{ $idCampo }}" class="form-label">Sede @if ($limitatoCampo)<span class="text-danger" aria-hidden="true">*</span>@endif</label>
        <select name="sede_id" id="{{ $idCampo }}" class="form-select @error('sede_id') is-invalid @enderror" @if ($limitatoCampo) required @endif>
            @unless ($limitatoCampo)
                <option value="">{{ $vuoto }}</option>
            @endunless
            @foreach ($sediCampo as $sedeCampo)
                <option value="{{ $sedeCampo->id }}" @selected((string) old('sede_id', $valore) === (string) $sedeCampo->id)>{{ $sedeCampo->nome }}</option>
            @endforeach
        </select>
        @error('sede_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@endif
