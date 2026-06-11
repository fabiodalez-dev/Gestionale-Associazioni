@extends('pubblico.layouts.app')

@section('title', 'Iscriviti — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-7">
            <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Iscriviti a {{ $associazione->nome }}</h1>
                    <p class="text-muted small mb-3">
                        Ciao {{ $account->nome }}! Compila il modulo per richiedere l'iscrizione come socio.
                        Nome, cognome ed email arrivano dal tuo account.
                    </p>

                    @if ($ultimaRifiutata !== null)
                        <div class="alert alert-warning" role="alert">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            La tua richiesta precedente è stata rifiutata
                            @if ($ultimaRifiutata->motivo_rifiuto)
                                con questo motivo: <strong>{{ $ultimaRifiutata->motivo_rifiuto }}</strong>.
                            @else
                                .
                            @endif
                            Puoi inviarne una nuova.
                        </div>
                    @endif

                    @if ($schema['intro_html'] !== null)
                        {{-- Già sanitizzata con mews/purifier da FormIscrizioneService --}}
                        <div class="mb-3">{!! $schema['intro_html'] !!}</div>
                    @endif

                    <form method="POST" action="{{ route('pubblico.iscriviti.store', $associazione->slug) }}" novalidate>
                        @csrf
                        <x-honeypot />

                        <div class="row g-3">
                            {{-- Campi anagrafici standard (schema-driven, solo i visibili) --}}
                            @foreach ($schema['campi_standard'] as $riga)
                                @continue(! $riga['visibile'])
                                @php
                                    $campo = $riga['campo'];
                                    $label = \App\Services\Iscrizioni\FormIscrizioneService::LABELS[$campo] ?? $campo;
                                    $tipo = match ($campo) {
                                        'telefono' => 'tel',
                                        'data_nascita' => 'date',
                                        default => 'text',
                                    };
                                @endphp
                                <div class="col-md-6">
                                    <label for="campo-{{ $campo }}" class="form-label">
                                        {{ $label }}
                                        @if ($riga['obbligatorio'])<span class="text-danger">*</span>@endif
                                    </label>
                                    <input type="{{ $tipo }}" name="{{ $campo }}" id="campo-{{ $campo }}"
                                           value="{{ old($campo) }}"
                                           class="form-control @error($campo) is-invalid @enderror"
                                           @required($riga['obbligatorio'])
                                           @if ($campo === 'cap') inputmode="numeric" maxlength="5" @endif
                                           @if ($campo === 'provincia') maxlength="2" @endif
                                           @if ($campo === 'codice_fiscale') maxlength="16" style="text-transform: uppercase;" @endif>
                                    @error($campo)
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endforeach

                            {{-- Campi personalizzati del tenant (tipi reali da config_campi) --}}
                            @foreach ($schema['campi_personalizzati'] as $campo)
                                @php
                                    $nome = 'custom_field['.$campo->id.']';
                                    $chiave = 'custom_field.'.$campo->id;
                                    $idCampo = 'campo-custom-'.$campo->id;
                                    $obbligatorio = $campo->obbligatorio && $campo->tipo_campo !== 'checkbox';
                                @endphp
                                <div class="col-12">
                                    @if ($campo->tipo_campo === 'checkbox')
                                        <div class="form-check">
                                            <input type="checkbox" name="{{ $nome }}" id="{{ $idCampo }}" value="1"
                                                   class="form-check-input @error($chiave) is-invalid @enderror"
                                                   @checked(old($chiave))>
                                            <label for="{{ $idCampo }}" class="form-check-label">{{ $campo->nome_campo }}</label>
                                            @error($chiave)
                                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @else
                                        <label for="{{ $idCampo }}" class="form-label">
                                            {{ $campo->nome_campo }}
                                            @if ($obbligatorio)<span class="text-danger">*</span>@endif
                                        </label>
                                        @if ($campo->tipo_campo === 'textarea')
                                            <textarea name="{{ $nome }}" id="{{ $idCampo }}" rows="3"
                                                      class="form-control @error($chiave) is-invalid @enderror"
                                                      @required($obbligatorio)>{{ old($chiave) }}</textarea>
                                        @elseif ($campo->tipo_campo === 'select')
                                            <select name="{{ $nome }}" id="{{ $idCampo }}"
                                                    class="form-select @error($chiave) is-invalid @enderror"
                                                    @required($obbligatorio)>
                                                <option value="">— Seleziona —</option>
                                                @foreach (array_filter(array_map('trim', explode("\n", (string) $campo->opzioni))) as $opzione)
                                                    <option value="{{ $opzione }}" @selected(old($chiave) === $opzione)>{{ $opzione }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input type="{{ in_array($campo->tipo_campo, ['email', 'tel', 'number', 'date', 'url'], true) ? $campo->tipo_campo : 'text' }}"
                                                   name="{{ $nome }}" id="{{ $idCampo }}" value="{{ old($chiave) }}"
                                                   class="form-control @error($chiave) is-invalid @enderror"
                                                   @required($obbligatorio)>
                                        @endif
                                        @error($chiave)
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    @endif
                                    @if ($campo->descrizione)
                                        <div class="form-text">{{ $campo->descrizione }}</div>
                                    @endif
                                </div>
                            @endforeach

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-person-heart me-1"></i> Invia la richiesta di iscrizione
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
