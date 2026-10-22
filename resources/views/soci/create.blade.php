@extends('layouts.app')

@section('title', 'Nuova iscrizione - '.session('app_name', config('app.name')))

@section('content')
    @php
        $req = fn (string $campo) => (bool) ($campiObbligatori[$campo] ?? false);
        $star = fn (string $campo) => $req($campo) ? ' *' : '';
        $tagSelezionati = (array) old('tag_ids', []);
        $costoBase = $associazione?->costo_tessera !== null ? (float) $associazione->costo_tessera : null;
        $iniziali = mb_strtoupper(collect(preg_split('/\s+/', trim((string) $associazione?->nome)) ?: [])->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join(''));
        $passi = [
            1 => ['Anagrafica', 'Dati personali'],
            2 => ['Tesseramento', 'Tipo, categoria e sede'],
            3 => ['Dati aggiuntivi', 'Campi e note interne'],
            4 => ['Privacy e conferma', 'Consenso e riepilogo'],
        ];
        // Passo da riaprire dopo un errore di validazione
        $campiPasso = [
            1 => ['nome', 'cognome', 'email', 'codice_fiscale', 'data_nascita', 'telefono', 'indirizzo', 'citta', 'provincia', 'cap', 'tutore_nome', 'tutore_telefono', 'tutore_email'],
            2 => ['tipo_socio_id', 'categoria_socio_id', 'sede_id', 'data_iscrizione', 'stato', 'tag_ids'],
            3 => ['custom_field', 'note'],
            4 => ['privacy_consenso'],
        ];
        $passoErrore = 1;
        if ($errors->any()) {
            $chiavi = collect($errors->keys());
            foreach ($campiPasso as $n => $campi) {
                if ($chiavi->contains(fn ($k) => in_array(\Illuminate\Support\Str::before($k, '.'), $campi, true))) {
                    $passoErrore = $n;
                    break;
                }
            }
        }
        $euro = fn (?float $v) => $v === null ? '—' : '€ '.number_format($v, 2, ',', '.');
    @endphp

    <div class="page-head">
        <div>
            <div class="crumb"><a href="{{ route('soci.index') }}">Soci</a> / <span>Nuovo socio</span></div>
            <h1>Nuova iscrizione</h1>
        </div>
        <div class="actions">
            <a href="{{ route('soci.import') }}" class="btn btn-outline-secondary"><i class="bi bi-upload"></i>Importa da Excel</a>
        </div>
    </div>

    <form method="POST" action="{{ route('soci.store') }}" id="wizardIscrizione" data-passo-iniziale="{{ $passoErrore }}" novalidate
          data-verifica-url="{{ route('soci.verifica-dati') }}">
        @csrf

        <ol class="stepper card" aria-label="Passi dell'iscrizione">
            @foreach ($passi as $n => [$titolo, $sotto])
                <li class="stepper-item" data-step-tab="{{ $n }}">
                    <button type="button" class="stepper-btn" data-vai-passo="{{ $n }}">
                        <span class="stepper-num">{{ $n }}</span>
                        <span class="stepper-text"><b>{{ $titolo }}</b><small>{{ $sotto }}</small></span>
                    </button>
                </li>
            @endforeach
        </ol>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-body p-4">
                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <strong>Iscrizione non salvata.</strong> Correggi i campi evidenziati.
                                <ul class="mb-0 mt-1">
                                    @foreach ($errors->all() as $messaggio)
                                        <li>{{ $messaggio }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Passo 1: anagrafica --}}
                        <section class="wizard-step" data-step="1">
                            <h2 class="h5 mb-1">Dati anagrafici</h2>
                            <p class="text-muted small mb-4">I campi con * sono obbligatori per il libro soci.</p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="nome">Nome *</label>
                                    <input type="text" class="form-control @error('nome') is-invalid @enderror" id="nome" name="nome" maxlength="100" required autocomplete="off" value="{{ old('nome') }}">
                                    @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="cognome">Cognome *</label>
                                    <input type="text" class="form-control @error('cognome') is-invalid @enderror" id="cognome" name="cognome" maxlength="100" required autocomplete="off" value="{{ old('cognome') }}">
                                    @error('cognome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="codice_fiscale">Codice fiscale{{ $star('codice_fiscale') }}</label>
                                    <input type="text" class="form-control mono text-uppercase @error('codice_fiscale') is-invalid @enderror" id="codice_fiscale" name="codice_fiscale" maxlength="16" @required($req('codice_fiscale')) autocomplete="off" value="{{ old('codice_fiscale') }}">
                                    <div class="invalid-feedback" data-feedback="codice_fiscale">@error('codice_fiscale'){{ $message }}@enderror</div>
                                    <div class="form-text" data-hint="codice_fiscale">Se valido, compila da solo la data di nascita.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="data_nascita">Data di nascita{{ $star('data_nascita') }}</label>
                                    <input type="date" class="form-control @error('data_nascita') is-invalid @enderror" id="data_nascita" name="data_nascita" @required($req('data_nascita')) max="{{ now()->toDateString() }}" value="{{ old('data_nascita') }}">
                                    @error('data_nascita')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="email">Email *</label>
                                    <div class="input-group has-validation">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" required autocomplete="off" value="{{ old('email') }}">
                                        <div class="invalid-feedback" data-feedback="email">@error('email'){{ $message }}@enderror</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="telefono">Telefono{{ $star('telefono') }}</label>
                                    <div class="input-group has-validation">
                                        <span class="input-group-text">+39</span>
                                        <input type="tel" class="form-control @error('telefono') is-invalid @enderror" id="telefono" name="telefono" maxlength="20" @required($req('telefono')) value="{{ old('telefono') }}" placeholder="333 123 4567">
                                        @error('telefono')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="indirizzo">Indirizzo{{ $star('indirizzo') }}</label>
                                    <input type="text" class="form-control @error('indirizzo') is-invalid @enderror" id="indirizzo" name="indirizzo" @required($req('indirizzo')) value="{{ old('indirizzo') }}">
                                    @error('indirizzo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="citta">Città{{ $star('citta') }}</label>
                                    <input type="text" class="form-control @error('citta') is-invalid @enderror" id="citta" name="citta" maxlength="100" @required($req('citta')) value="{{ old('citta') }}">
                                    @error('citta')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-1 col-4">
                                    <label class="form-label" for="provincia">Prov.{{ $star('provincia') }}</label>
                                    <input type="text" class="form-control text-uppercase @error('provincia') is-invalid @enderror" id="provincia" name="provincia" maxlength="2" @required($req('provincia')) value="{{ old('provincia') }}">
                                </div>
                                <div class="col-md-2 col-8">
                                    <label class="form-label" for="cap">CAP{{ $star('cap') }}</label>
                                    <input type="text" class="form-control @error('cap') is-invalid @enderror" id="cap" name="cap" maxlength="5" inputmode="numeric" @required($req('cap')) value="{{ old('cap') }}">
                                </div>
                            </div>

                            {{-- Tutore per soci minorenni (f.22): compare quando l'età è < 18 --}}
                            <div class="tutore-box mt-4 {{ filled(old('tutore_nome')) ? '' : 'd-none' }}" id="cardTutore">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="icon-tile icon-tile-sm tone-2"><i class="bi bi-person-exclamation"></i></span>
                                    <div>
                                        <div class="fw-semibold text-body">Tutore</div>
                                        <div class="small text-muted">Il socio è minorenne: nome, telefono ed email del tutore sono obbligatori.</div>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label" for="tutore_nome">Nome del tutore *</label>
                                        <input type="text" class="form-control @error('tutore_nome') is-invalid @enderror" id="tutore_nome" name="tutore_nome" maxlength="150" value="{{ old('tutore_nome') }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="tutore_telefono">Telefono del tutore *</label>
                                        <input type="tel" class="form-control @error('tutore_telefono') is-invalid @enderror" id="tutore_telefono" name="tutore_telefono" maxlength="30" value="{{ old('tutore_telefono') }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="tutore_email">Email del tutore *</label>
                                        <input type="email" class="form-control @error('tutore_email') is-invalid @enderror" id="tutore_email" name="tutore_email" maxlength="255" value="{{ old('tutore_email') }}">
                                    </div>
                                </div>
                            </div>
                        </section>

                        {{-- Passo 2: tesseramento --}}
                        <section class="wizard-step" data-step="2" hidden>
                            <h2 class="h5 mb-1">Tipo di adesione</h2>
                            <p class="text-muted small mb-3">La quota viene dal listino dei tipi socio{{ $associazione ? ' di '.$associazione->nome : '' }}.</p>
                            <div class="choice-grid mb-4" role="radiogroup" aria-label="Tipo socio{{ $req('tipo_socio_id') ? ' (obbligatorio)' : '' }}">
                                @unless ($req('tipo_socio_id'))
                                    <label class="choice-card">
                                        <input type="radio" name="tipo_socio_id" value="" data-costo="{{ $costoBase }}" data-nome="Nessun tipo" @checked(old('tipo_socio_id', '') === '')>
                                        <span class="choice-top"><span class="choice-radio"></span><span class="choice-price">{{ $costoBase !== null ? $euro($costoBase) : '—' }}</span></span>
                                        <span class="choice-title">Nessun tipo</span>
                                        <span class="choice-sub">Quota standard dell'associazione</span>
                                    </label>
                                @endunless
                                @foreach ($tipiSocio as $tipo)
                                    @php $costo = $tipo->costo_tessera !== null ? (float) $tipo->costo_tessera : $costoBase; @endphp
                                    <label class="choice-card">
                                        <input type="radio" name="tipo_socio_id" value="{{ $tipo->id }}" data-costo="{{ $costo }}" data-nome="{{ $tipo->nome }}" @checked(old('tipo_socio_id') === $tipo->id) @required($req('tipo_socio_id'))>
                                        <span class="choice-top"><span class="choice-radio"></span><span class="choice-price">{{ $euro($costo) }}</span></span>
                                        <span class="choice-title">{{ $tipo->nome }}</span>
                                        <span class="choice-sub">{{ $tipo->costo_tessera !== null ? 'Quota del tipo socio' : 'Quota standard dell\'associazione' }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('tipo_socio_id')<div class="text-danger small mb-3">{{ $message }}</div>@enderror

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="categoria_socio_id">Categoria{{ $star('categoria_socio_id') }}</label>
                                    <select class="form-select @error('categoria_socio_id') is-invalid @enderror" id="categoria_socio_id" name="categoria_socio_id" @required($req('categoria_socio_id'))>
                                        <option value="">— Nessuna —</option>
                                        @foreach ($categorie as $categoria)
                                            <option value="{{ $categoria->id }}" @selected(old('categoria_socio_id') === $categoria->id)>{{ $categoria->nome }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="sede_id">Sede{{ $star('sede_id') }}</label>
                                    <select class="form-select @error('sede_id') is-invalid @enderror" id="sede_id" name="sede_id" @required($req('sede_id'))>
                                        <option value="">— Nessuna —</option>
                                        @foreach ($sedi as $sede)
                                            <option value="{{ $sede->id }}" @selected(old('sede_id', $sedi->count() === 1 ? $sede->id : null) === $sede->id)>{{ $sede->nome }}</option>
                                        @endforeach
                                    </select>
                                    @error('sede_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="data_iscrizione">Data di iscrizione *</label>
                                    <input type="date" class="form-control @error('data_iscrizione') is-invalid @enderror" id="data_iscrizione" name="data_iscrizione" required value="{{ old('data_iscrizione', now()->toDateString()) }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="stato">Stato *</label>
                                    <select class="form-select" id="stato" name="stato" required>
                                        @foreach ($stati as $stato)
                                            <option value="{{ $stato }}" @selected(old('stato', 'Attivo') === $stato)>{{ $stato }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @if ($tags->isNotEmpty())
                                    <div class="col-12">
                                        <span class="form-label d-block">Tag</span>
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach ($tags as $tag)
                                                <label class="tag-toggle">
                                                    <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $tagSelezionati, true))>
                                                    <span class="badge tag-chip" style="--tag: {{ $tag->colore ?: '#8A90A0' }}">{{ $tag->nome_tag }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="genera_tessera" value="0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="genera_tessera" name="genera_tessera" value="1" @checked(old('genera_tessera', true))>
                                        <label class="form-check-label" for="genera_tessera">Genera subito la tessera per l'anno {{ now()->year }}</label>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {{-- Passo 3: dati aggiuntivi --}}
                        <section class="wizard-step" data-step="3" hidden>
                            <h2 class="h5 mb-1">Dati aggiuntivi</h2>
                            <p class="text-muted small mb-4">Campi personalizzati dell'associazione e note visibili solo alla segreteria.</p>
                            @if ($campiPersonalizzati->isNotEmpty())
                                <div class="row g-3 mb-3">
                                    @foreach ($campiPersonalizzati as $campo)
                                        @php
                                            $valore = old("custom_field.{$campo->id}", '');
                                            $name = "custom_field[{$campo->id}]";
                                        @endphp
                                        <div class="{{ $campo->tipo_campo === 'textarea' ? 'col-12' : 'col-md-6' }}">
                                            @if ($campo->tipo_campo === 'checkbox')
                                                <div class="form-check mt-md-4">
                                                    <input class="form-check-input" type="checkbox" id="campo_{{ $campo->id }}" name="{{ $name }}" value="1" @checked($valore === '1')>
                                                    <label class="form-check-label" for="campo_{{ $campo->id }}">{{ $campo->nome_campo }}</label>
                                                </div>
                                            @else
                                                <label class="form-label" for="campo_{{ $campo->id }}">{{ $campo->nome_campo }}{{ $campo->obbligatorio ? ' *' : '' }}</label>
                                                @if ($campo->tipo_campo === 'textarea')
                                                    <textarea class="form-control @error('custom_field.'.$campo->id) is-invalid @enderror" id="campo_{{ $campo->id }}" name="{{ $name }}" rows="2" @required($campo->obbligatorio)>{{ $valore }}</textarea>
                                                @elseif ($campo->tipo_campo === 'select')
                                                    <select class="form-select @error('custom_field.'.$campo->id) is-invalid @enderror" id="campo_{{ $campo->id }}" name="{{ $name }}" @required($campo->obbligatorio)>
                                                        <option value="">— Seleziona —</option>
                                                        @foreach (array_filter(array_map('trim', explode("\n", (string) $campo->opzioni))) as $opzione)
                                                            <option value="{{ $opzione }}" @selected($valore === $opzione)>{{ $opzione }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <input type="{{ $campo->tipo_campo }}" class="form-control @error('custom_field.'.$campo->id) is-invalid @enderror" id="campo_{{ $campo->id }}" name="{{ $name }}" value="{{ $valore }}" @required($campo->obbligatorio)>
                                                @endif
                                                @error('custom_field.'.$campo->id)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            @endif
                                            @if ($campo->descrizione)
                                                <div class="form-text">{{ $campo->descrizione }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="empty-hint mb-3">
                                    <i class="bi bi-ui-checks"></i>
                                    Nessun campo personalizzato. Puoi aggiungerne in <a href="{{ route('config-campi.index') }}">Campi personalizzati</a>.
                                </div>
                            @endif
                            <label class="form-label" for="note">Note interne{{ $star('note') }}</label>
                            <textarea class="form-control @error('note') is-invalid @enderror" id="note" name="note" rows="3" placeholder="Visibili solo alla segreteria" @required($req('note'))>{{ old('note') }}</textarea>
                            <div class="form-text">Il socio le ritrova nell'archivio dei suoi dati personali, se ne chiede una copia.</div>
                        </section>

                        {{-- Passo 4: privacy e conferma --}}
                        <section class="wizard-step" data-step="4" hidden>
                            <h2 class="h5 mb-1">Privacy e conferma</h2>
                            <p class="text-muted small mb-4">Controlla i dati prima di creare il socio.</p>
                            <dl class="kv mb-4" id="riepilogoDati"></dl>
                            <div class="consenso-box">
                                <div class="form-check">
                                    <input class="form-check-input @error('privacy_consenso') is-invalid @enderror" type="checkbox" id="privacy_consenso" name="privacy_consenso" value="1" @checked(old('privacy_consenso')) @required($req('privacy_consenso'))>
                                    <label class="form-check-label" for="privacy_consenso">
                                        Il socio ha ricevuto l'informativa e acconsente al trattamento dei dati{{ $star('privacy_consenso') }}
                                        @if ($associazione)
                                            — <a href="{{ route('privacy-policy.show', ['associazione' => $associazione->id]) }}" target="_blank" rel="noopener">leggi l'informativa</a>
                                        @endif
                                    </label>
                                </div>
                            </div>
                        </section>

                        <div class="wizard-nav">
                            <button type="button" class="btn btn-light" data-indietro>Indietro</button>
                            <div class="d-flex gap-2">
                                <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary">Annulla</a>
                                <button type="button" class="btn btn-primary" data-avanti>Continua<i class="bi bi-arrow-right"></i></button>
                                <button type="submit" class="btn btn-primary" data-conferma hidden><i class="bi bi-check-lg"></i>Crea socio</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="wizard-aside">
                    <div class="card">
                        <div class="card-body">
                            <h2 class="h6 mb-3">Riepilogo</h2>
                            <div class="d-flex align-items-center gap-2 p-2 rounded-3 mb-3" style="background: var(--ds-surface-2)">
                                <span class="avatar avatar-sm avatar-square">{{ $iniziali ?: '?' }}</span>
                                <div class="min-w-0">
                                    <div class="small text-muted">Associazione</div>
                                    <div class="fw-semibold text-body text-truncate">{{ $associazione?->nome }}</div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between py-1"><span>Socio</span><span class="text-body fw-medium text-end" data-riepilogo="nome">—</span></div>
                            <div class="d-flex justify-content-between py-1"><span>Tipo di adesione</span><span class="text-body" data-riepilogo="tipo">—</span></div>
                            <div class="d-flex justify-content-between py-1"><span>Tessera {{ now()->year }}</span><span class="text-body" data-riepilogo="tessera">Sì</span></div>
                            <hr>
                            <div class="d-flex justify-content-between align-items-baseline">
                                <span class="fw-semibold text-body">Quota</span>
                                <span class="fs-4 fw-bold text-body" data-riepilogo="quota">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <h2 class="h6 mb-3">Controlli automatici</h2>
                            <ul class="checks" id="controlliAutomatici" aria-live="polite">
                                <li data-check="email" class="is-pending"><i class="bi"></i><span>Email da inserire</span></li>
                                <li data-check="cf" class="is-pending"><i class="bi"></i><span>Codice fiscale da inserire</span></li>
                                <li data-check="eta" class="is-pending"><i class="bi"></i><span>Età da verificare</span></li>
                                <li data-check="obbligatori" class="is-pending"><i class="bi"></i><span>Campi obbligatori</span></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/nuova-iscrizione.js') }}"></script>
@endpush
