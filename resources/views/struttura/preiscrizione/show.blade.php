<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $socioRinnovo ? 'Rinnovo iscrizione' : 'Pre-iscrizione' }} — {{ $associazione->nome }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        body { background: #F3F4F6; }
        .brand-header { background: #FF7B11; color: #FFFFFF; padding: 1.5rem 0; }
        .brand-header img { max-height: 56px; background: #FFFFFF; border-radius: 8px; padding: 4px; }
        .form-card { max-width: 760px; margin: -1.5rem auto 2rem; }
        .required-star { color: #DC2626; }
        .btn-brand { background: #FF7B11; border-color: #FF7B11; color: #FFFFFF; }
        .btn-brand:hover { background: #E86A00; border-color: #E86A00; color: #FFFFFF; }
        footer { color: #9CA3AF; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="brand-header text-center mb-5">
        @if ($associazione->logo_url)
            <img src="{{ asset('storage/'.$associazione->logo_url) }}" alt="Logo {{ $associazione->nome }}" class="mb-2"><br>
        @endif
        <h1 class="h4 mb-0">{{ $associazione->nome }}</h1>
    </div>

    <div class="container form-card">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $errore)
                        <li>{{ $errore }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($tokenScaduto)
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Il link di rinnovo è scaduto o non è valido. Contatta l'associazione a
                <a href="mailto:{{ $associazione->email }}">{{ $associazione->email }}</a>.
            </div>
        @else
            @php
                $required = fn (string $campo): bool => $requiredConfig['preiscrizione'][$campo] ?? false;
                $valore = fn (string $campo) => old($campo, $socioRinnovo?->{$campo});
            @endphp

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">
                        {{ $socioRinnovo ? 'Rinnovo iscrizione a '.$associazione->nome : 'Richiesta di pre-iscrizione' }}
                    </h2>

                    <form method="POST" action="{{ route('preiscrizione.store', ['associazione' => $associazione->id]) }}" id="formPreiscrizione">
                        @csrf
                        <x-honeypot />
                        @if ($renewalToken)
                            <input type="hidden" name="renewal_token" value="{{ $renewalToken }}">
                        @endif

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="nome">Nome <span class="required-star">*</span></label>
                                <input type="text" class="form-control" id="nome" name="nome" required maxlength="100" value="{{ $valore('nome') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="cognome">Cognome <span class="required-star">*</span></label>
                                <input type="text" class="form-control" id="cognome" name="cognome" required maxlength="100" value="{{ $valore('cognome') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email <span class="required-star">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required value="{{ $valore('email') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="telefono">Telefono @if ($required('telefono'))<span class="required-star">*</span>@endif</label>
                                <input type="tel" class="form-control" id="telefono" name="telefono" maxlength="20" @required($required('telefono')) value="{{ $valore('telefono') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="data_nascita">Data di nascita @if ($required('data_nascita'))<span class="required-star">*</span>@endif</label>
                                <input type="date" class="form-control" id="data_nascita" name="data_nascita" @required($required('data_nascita'))
                                       value="{{ old('data_nascita', optional($socioRinnovo?->data_nascita)->format('Y-m-d')) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="codice_fiscale">Codice fiscale @if ($required('codice_fiscale'))<span class="required-star">*</span>@endif</label>
                                <input type="text" class="form-control" id="codice_fiscale" name="codice_fiscale" maxlength="16" @required($required('codice_fiscale')) value="{{ $valore('codice_fiscale') }}">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="indirizzo">Indirizzo @if ($required('indirizzo'))<span class="required-star">*</span>@endif</label>
                                <input type="text" class="form-control" id="indirizzo" name="indirizzo" @required($required('indirizzo')) value="{{ $valore('indirizzo') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="citta">Città @if ($required('citta'))<span class="required-star">*</span>@endif</label>
                                <input type="text" class="form-control" id="citta" name="citta" maxlength="100" @required($required('citta')) value="{{ $valore('citta') }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="provincia">Prov. @if ($required('provincia'))<span class="required-star">*</span>@endif</label>
                                <input type="text" class="form-control" id="provincia" name="provincia" maxlength="2" @required($required('provincia')) value="{{ $valore('provincia') }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="cap">CAP @if ($required('cap'))<span class="required-star">*</span>@endif</label>
                                <input type="text" class="form-control" id="cap" name="cap" maxlength="5" @required($required('cap')) value="{{ $valore('cap') }}">
                            </div>

                            @if ($tipiSocio->isNotEmpty())
                                <div class="col-md-6">
                                    <label class="form-label" for="tipo_socio_id">Tipo socio @if ($required('tipo_socio_id'))<span class="required-star">*</span>@endif</label>
                                    <select class="form-select" id="tipo_socio_id" name="tipo_socio_id" @required($required('tipo_socio_id'))>
                                        <option value="">-- Seleziona --</option>
                                        @foreach ($tipiSocio as $tipo)
                                            <option value="{{ $tipo->id }}" @selected($valore('tipo_socio_id') === $tipo->id)>{{ $tipo->nome }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            @if ($categorieSocio->isNotEmpty())
                                <div class="col-md-6">
                                    <label class="form-label" for="categoria_socio_id">Categoria socio @if ($required('categoria_socio_id'))<span class="required-star">*</span>@endif</label>
                                    <select class="form-select" id="categoria_socio_id" name="categoria_socio_id" @required($required('categoria_socio_id'))>
                                        <option value="">-- Seleziona --</option>
                                        @foreach ($categorieSocio as $categoria)
                                            <option value="{{ $categoria->id }}" @selected($valore('categoria_socio_id') === $categoria->id)>{{ $categoria->nome }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @foreach ($campiPersonalizzati as $campo)
                                @php
                                    $campoRequired = (bool) $campo->obbligatorio_preiscrizione && $campo->tipo_campo !== 'checkbox';
                                    $campoValore = old('custom_field.'.$campo->id, $valoriCustom[$campo->id] ?? '');
                                @endphp
                                <div class="{{ $campo->tipo_campo === 'textarea' ? 'col-12' : 'col-md-6' }}">
                                    @if ($campo->tipo_campo === 'checkbox')
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" id="custom_{{ $campo->id }}"
                                                   name="custom_field[{{ $campo->id }}]" value="1" @checked($campoValore)>
                                            <label class="form-check-label" for="custom_{{ $campo->id }}">{{ $campo->nome_campo }}</label>
                                        </div>
                                    @else
                                        <label class="form-label" for="custom_{{ $campo->id }}">
                                            {{ $campo->nome_campo }} @if ($campoRequired)<span class="required-star">*</span>@endif
                                        </label>
                                        @if ($campo->tipo_campo === 'textarea')
                                            <textarea class="form-control" id="custom_{{ $campo->id }}" name="custom_field[{{ $campo->id }}]" rows="3" @required($campoRequired)>{{ $campoValore }}</textarea>
                                        @elseif ($campo->tipo_campo === 'select')
                                            <select class="form-select" id="custom_{{ $campo->id }}" name="custom_field[{{ $campo->id }}]" @required($campoRequired)>
                                                <option value="">-- Seleziona --</option>
                                                @foreach (array_filter(array_map('trim', explode("\n", (string) $campo->opzioni))) as $opzione)
                                                    <option value="{{ $opzione }}" @selected($campoValore === $opzione)>{{ $opzione }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input type="{{ $campo->tipo_campo }}" class="form-control" id="custom_{{ $campo->id }}"
                                                   name="custom_field[{{ $campo->id }}]" @required($campoRequired) value="{{ $campoValore }}">
                                        @endif
                                        @if ($campo->descrizione)
                                            <div class="form-text">{{ $campo->descrizione }}</div>
                                        @endif
                                    @endif
                                </div>
                            @endforeach

                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="privacy_consenso" name="privacy_consenso" value="1" required
                                           @checked(old('privacy_consenso', (bool) $socioRinnovo))>
                                    <label class="form-check-label" for="privacy_consenso">
                                        Accetto l'informativa sulla privacy <span class="required-star">*</span>
                                        @if (filled($associazione->privacy_policy_html))
                                            — <a href="{{ route('privacy-policy.show', ['associazione' => $associazione->id]) }}" target="_blank" rel="noopener">leggi l'informativa</a>
                                        @endif
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <button class="btn btn-brand btn-lg w-100" type="submit" id="btnInvia">
                                    <span class="spinner-border spinner-border-sm d-none me-2" id="spinnerInvio" role="status" aria-hidden="true"></span>
                                    {{ $socioRinnovo ? 'Aggiorna dati' : 'Invia richiesta di pre-iscrizione' }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <footer class="text-center pb-4">{{ $associazione->nome }}</footer>

    <script>
        // Anti doppio-invio: spinner + disable (spec §6.8)
        document.getElementById('formPreiscrizione')?.addEventListener('submit', function () {
            var btn = document.getElementById('btnInvia');
            document.getElementById('spinnerInvio').classList.remove('d-none');
            setTimeout(function () { btn.disabled = true; }, 0);
        });
    </script>
</body>
</html>
