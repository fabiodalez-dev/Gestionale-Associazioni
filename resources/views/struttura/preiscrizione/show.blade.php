<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $socioRinnovo ? 'Rinnovo iscrizione' : 'Pre-iscrizione' }} — {{ $associazione->nome }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    @include('partials.stile-associazione')
    <style>
        body {
            background-color: var(--ds-bg);
            background-image: radial-gradient(900px 420px at 50% -160px, var(--ds-accent-subtle), transparent 75%);
            background-repeat: no-repeat;
        }
        .form-card { max-width: 760px; margin: 0 auto; padding: 40px 16px 24px; }
        .modulo { background: var(--ds-surface); border-radius: 16px; box-shadow: var(--ds-shadow-md); overflow: hidden; }
        .modulo-head { display: flex; align-items: center; gap: 16px; padding: 28px 32px 22px; border-bottom: 1px solid var(--ds-line); }
        .modulo-marchio { width: 56px; height: 56px; flex: none; border-radius: 14px; background: var(--brand); color: var(--brand-testo); display: grid; place-items: center; font-weight: 700; font-size: 1.125rem; overflow: hidden; box-shadow: var(--ds-shadow-accent); }
        .modulo-marchio img { width: 100%; height: 100%; object-fit: contain; background: #fff; padding: 4px; }
        .modulo-kicker { font-size: 0.8125rem; color: var(--ds-text-muted); }
        .modulo-head h1 { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.02em; margin: 2px 0 0; line-height: 1.25; }
        .modulo-progress { display: flex; gap: 6px; padding: 0 32px; }
        .modulo-progress span { flex: 1; height: 4px; border-radius: 999px; background: var(--ds-surface-3); }
        .modulo-progress span.on { background: var(--brand); }
        .modulo-body { padding: 24px 32px 32px; }
        .intro-modulo { color: var(--ds-text-2); line-height: 1.65; }
        .required-star { color: var(--ds-danger); }
        .btn-brand { background: var(--brand); border-color: var(--brand); color: var(--brand-testo); min-height: 50px; font-weight: 600; box-shadow: var(--ds-shadow-accent); }
        .btn-brand:hover, .btn-brand:focus { background: var(--brand-hover); border-color: var(--brand-hover); color: var(--brand-testo); }
        .modulo-sicuro { text-align: center; font-size: 0.75rem; color: var(--ds-text-muted); margin-top: 12px; }
        footer { color: var(--ds-text-muted); font-size: 0.8125rem; }
        @media (max-width: 575.98px) {
            .form-card { padding-top: 16px; }
            .modulo-head, .modulo-body { padding-left: 20px; padding-right: 20px; }
            .modulo-progress { padding: 0 20px; }
        }
    </style>
</head>
<body>
    <div class="container form-card">
        @if (session('success'))
            <div class="alert alert-success">{{ $associazione->moduli()->messaggio_conferma && session('preiscrizione_inviata') ? $associazione->moduli()->messaggio_conferma : session('success') }}</div>
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
            <div class="modulo">
                <div class="modulo-head">
                    <span class="modulo-marchio">
                        @if ($associazione->moduli()->mostra_logo && $associazione->logoUrl())
                            <img src="{{ $associazione->logoUrl('miniatura') }}" alt="Logo {{ $associazione->nome }}">
                        @else
                            {{ mb_strtoupper(collect(preg_split('/\s+/', trim($associazione->nome)) ?: [])->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('')) }}
                        @endif
                    </span>
                    <div>
                        <div class="modulo-kicker">{{ $associazione->nome }}</div>
                        <h1>{{ $socioRinnovo ? 'Rinnovo iscrizione' : ($associazione->moduli()->titolo_preiscrizione ?: 'Richiesta di pre-iscrizione') }}</h1>
                    </div>
                </div>
                <div class="modulo-body">
                    @if (! $socioRinnovo && filled($associazione->moduli()->intro_preiscrizione_html))
                        {{-- HTML purificato in scrittura (Configurazioni → Moduli pubblici) --}}
                        <div class="mb-4 intro-modulo">{!! $associazione->moduli()->intro_preiscrizione_html !!}</div>
                    @endif

                    <form method="POST" action="{{ route('preiscrizione.store', ['associazione' => $associazione->id]) }}" id="formPreiscrizione">
                        @csrf
                        <x-honeypot />
                        @if ($renewalToken)
                            <input type="hidden" name="renewal_token" value="{{ $renewalToken }}">
                        @endif

                        @php
                            $formPreiscrizione = \App\Forms\PreiscrizioneForm::nuovo($associazione, $socioRinnovo, $requiredConfig['preiscrizione'] ?? [], $tipiSocio, $categorieSocio);
                        @endphp
                        @include('formello.campi', ['form' => $formPreiscrizione, 'solo' => \App\Forms\PreiscrizioneForm::CAMPI_ANAGRAFICA])

                        <div class="row g-3">
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
                                @include('formello.campi', ['form' => $formPreiscrizione, 'solo' => ['privacy_consenso']])
                                @include('partials.consensi-modulo', ['associazione' => $associazione])
                            </div>

                            <div class="col-12">
                                <button class="btn btn-brand btn-lg w-100" type="submit" id="btnInvia">
                                    <span class="spinner-border spinner-border-sm d-none me-2" id="spinnerInvio" role="status" aria-hidden="true"></span>
                                    {{ $socioRinnovo ? 'Aggiorna dati' : 'Invia richiesta di pre-iscrizione' }}
                                </button>
                                <div class="modulo-sicuro"><i class="bi bi-shield-lock me-1"></i>I tuoi dati sono trattati secondo l'informativa privacy dell'associazione.</div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <footer class="text-center pb-4">{{ $associazione->nome }}@if ($associazione->email) · <a href="mailto:{{ $associazione->email }}">{{ $associazione->email }}</a>@endif</footer>

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
