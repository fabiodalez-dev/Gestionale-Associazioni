@extends('layouts.app')

@section('title', 'Impostazioni di sistema - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-end pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-gear-wide-connected me-2"></i>Impostazioni di sistema</h1>
            <p class="text-muted mb-0">Configurazione della piattaforma salvata nel file <code>.env</code> (solo super admin).</p>
        </div>
    </div>

    @foreach ($avvisi as $avviso)
        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $avviso }}</div>
    @endforeach

    @unless ($scrivibile)
        <div class="alert alert-danger"><i class="bi bi-lock me-1"></i>Il file <code>.env</code> non è scrivibile dal server web: le impostazioni sono in sola lettura.</div>
    @endunless

    <ul class="nav nav-tabs mb-4 flex-nowrap overflow-auto">
        @foreach ($sezioni as $chiave => $s)
            <li class="nav-item">
                <a class="nav-link text-nowrap {{ $attiva === $chiave ? 'active' : '' }}" href="{{ route('impostazioni-sistema.index', ['sezione' => $chiave]) }}" @if ($attiva === $chiave) aria-current="page" @endif>
                    <i class="bi {{ $s['icona'] }} me-1"></i>{{ $s['titolo'] }}
                </a>
            </li>
        @endforeach
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $attiva === 'sistema' ? 'active' : '' }}" href="{{ route('impostazioni-sistema.index', ['sezione' => 'sistema']) }}" @if ($attiva === 'sistema') aria-current="page" @endif>
                <i class="bi bi-info-circle me-1"></i>Info sistema
            </a>
        </li>
    </ul>

    @if ($attiva === 'sistema')
        @include('impostazioni-sistema.partials.info', ['info' => $info])
    @else
        @php($sezione = $sezioni[$attiva])
        <div class="row g-4">
            <div class="col-lg-8">
                <form method="POST" action="{{ route('impostazioni-sistema.update', ['sezione' => $attiva]) }}" class="card" id="formImpostazioni"
                      @if ($attiva === 'database') onsubmit="return event.submitter && event.submitter.formAction && event.submitter.formAction.indexOf('/test') !== -1 ? true : confirm('Salvare la nuova configurazione del database? La connessione viene verificata prima del salvataggio.');" @endif>
                    @csrf
                    @method('PUT')
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi {{ $sezione['icona'] }} me-1"></i>{{ $sezione['titolo'] }}</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">{{ $sezione['descrizione'] }}</p>
                        <div class="row">
                            @foreach ($sezione['campi'] as $chiave => $campo)
                                @php($attuale = old($chiave, $valori[$chiave] ?? ($campo['default'] ?? '')))
                                <div class="{{ in_array($campo['tipo'], ['bool'], true) ? 'col-12' : 'col-md-6' }} mb-3">
                                    @if ($campo['tipo'] === 'bool')
                                        <div class="form-check form-switch">
                                            <input type="hidden" name="{{ $chiave }}" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="{{ $chiave }}" name="{{ $chiave }}" value="1" @checked(filter_var($attuale, FILTER_VALIDATE_BOOLEAN)) @disabled(! $scrivibile)>
                                            <label class="form-check-label" for="{{ $chiave }}">{{ $campo['label'] }}</label>
                                        </div>
                                    @else
                                        <label class="form-label" for="{{ $chiave }}">{{ $campo['label'] }}</label>
                                        @if ($campo['tipo'] === 'select')
                                            <select class="form-select @error($chiave) is-invalid @enderror" id="{{ $chiave }}" name="{{ $chiave }}" @disabled(! $scrivibile)>
                                                @foreach ($campo['opzioni'] as $valore => $etichetta)
                                                    <option value="{{ $valore }}" @selected((string) $attuale === (string) $valore)>{{ $etichetta }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($campo['tipo'] === 'password')
                                            @php($impostata = filled($valori[$chiave] ?? null))
                                            <div class="input-group">
                                                <input type="password" class="form-control @error($chiave) is-invalid @enderror" id="{{ $chiave }}" name="{{ $chiave }}" autocomplete="new-password"
                                                       placeholder="{{ $impostata ? '•••••••• impostata (lascia vuoto per non modificare)' : 'Non impostata' }}" @disabled(! $scrivibile)>
                                                <button class="btn btn-outline-secondary" type="button" data-toggle-password="{{ $chiave }}" aria-label="Mostra {{ $campo['label'] }}"><i class="bi bi-eye"></i></button>
                                            </div>
                                            @if ($impostata)
                                                <div class="form-check mt-1">
                                                    <input class="form-check-input" type="checkbox" id="{{ $chiave }}_rimuovi" name="{{ $chiave }}_rimuovi" value="1" @disabled(! $scrivibile)>
                                                    <label class="form-check-label small" for="{{ $chiave }}_rimuovi">Rimuovi il valore salvato</label>
                                                </div>
                                            @endif
                                        @else
                                            <input type="{{ $campo['tipo'] }}" class="form-control @error($chiave) is-invalid @enderror" id="{{ $chiave }}" name="{{ $chiave }}" value="{{ $attuale }}"
                                                   placeholder="{{ $campo['placeholder'] ?? '' }}" autocomplete="off" @disabled(! $scrivibile)>
                                        @endif
                                        @error($chiave)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    @endif
                                    @if (! empty($campo['help']))
                                        <div class="form-text">{{ $campo['help'] }}</div>
                                    @endif
                                    <div class="form-text"><code class="small text-body-tertiary">{{ $chiave }}</code></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" @disabled(! $scrivibile)><i class="bi bi-check-lg me-1"></i>Salva</button>
                        @if ($attiva === 'database')
                            <button type="submit" class="btn btn-outline-primary" formaction="{{ route('impostazioni-sistema.test-database') }}" formmethod="POST"><i class="bi bi-plug me-1"></i>Verifica connessione</button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="col-lg-4">
                @if ($attiva === 'email')
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-send-check me-1"></i>Prova invio</h5></div>
                        <div class="card-body">
                            <p class="small text-muted">Invia un'email di prova con i valori del modulo, anche prima di salvarli.</p>
                            <label class="form-label" for="test_destinatario">Destinatario</label>
                            <input type="email" class="form-control mb-3" id="test_destinatario" name="test_destinatario" form="formImpostazioni" value="{{ old('test_destinatario', auth()->user()->email) }}">
                            <button type="submit" class="btn btn-outline-primary w-100" form="formImpostazioni" formaction="{{ route('impostazioni-sistema.test-email') }}" formmethod="POST">
                                <i class="bi bi-send me-1"></i>Invia email di prova
                            </button>
                        </div>
                    </div>
                @endif
                <div class="card">
                    <div class="card-body small text-muted">
                        <p class="mb-2"><i class="bi bi-shield-check me-1"></i>Ogni salvataggio crea un backup del file <code>.env</code> in <code>storage/app/private/env-backups</code>, fuori dalla cartella pubblica.</p>
                        <p class="mb-0"><i class="bi bi-arrow-clockwise me-1"></i>Le modifiche valgono dalla richiesta successiva. Se usi un worker delle code, riavvialo perché legga i nuovi valori.</p>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-toggle-password'));
                var mostra = input.type === 'password';
                input.type = mostra ? 'text' : 'password';
                btn.querySelector('i').className = mostra ? 'bi bi-eye-slash' : 'bi bi-eye';
            });
        });
    </script>
@endpush
