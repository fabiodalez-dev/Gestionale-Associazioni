@extends('layouts.app')

@section('title', 'Gestione Associazioni')

@section('content')
@php
    $iniziali = fn (?string $t) => mb_strtoupper(collect(preg_split('/\s+/', trim((string) $t)) ?: [])->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('')) ?: '?';
    $euroBreve = fn (float $v) => $v >= 1000 ? '€ '.number_format($v / 1000, $v >= 10000 ? 0 : 1, ',', '.').'k' : '€ '.number_format($v, 0, ',', '.');
    $etichetteRegistrazione = ['disattivata' => 'Solo super admin', 'revisione' => 'Con approvazione', 'automatica' => 'Automatica'];
    $tonoMatrice = ['Completo' => 'bg-success', 'Modifica' => 'bg-info', 'Lettura' => 'bg-secondary'];
@endphp

<div class="page-head">
    <div>
        <div class="crumb">Piattaforma / <span>Associazioni</span></div>
        <h1>Associazioni</h1>
    </div>
    <div class="actions">
        @if (Route::has('richieste-registrazione.index'))
            <a href="{{ route('richieste-registrazione.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-inbox"></i>Richieste di iscrizione
                @if ($piattaforma['richieste'] > 0)<span class="badge bg-danger">{{ $piattaforma['richieste'] }}</span>@endif
            </a>
        @endif
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalAssociazione" id="btnNuovaAssociazione">
            <i class="bi bi-plus-lg"></i>Nuova Associazione
        </button>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="facts-grid">
            <div><span>Associazioni attive</span><b>{{ $piattaforma['attive'] }} di {{ $piattaforma['totali'] }}</b></div>
            <div><span>Soci in totale</span><b>{{ number_format($piattaforma['soci'], 0, ',', '.') }}</b></div>
            <div><span>Utenti dello staff attivi</span><b>{{ number_format($piattaforma['utenti'], 0, ',', '.') }}</b></div>
            <div><span>Quote incassate {{ now()->year }}</span><b>{{ $euroBreve($piattaforma['incassi']) }}</b></div>
            <div><span>Iscrizione nuove associazioni</span><b><a href="{{ route('impostazioni-sistema.index', ['sezione' => 'registrazione']) }}">{{ $etichetteRegistrazione[$piattaforma['registrazione']] }}</a></b></div>
            <div><span>Dominio</span><b class="mono">{{ parse_url(config('app.url'), PHP_URL_HOST) ?: request()->getHost() }}</b></div>
        </div>
    </div>
</div>

@if ($associazioni->isEmpty())
    <div class="card"><div class="empty-state"><span class="icon-tile tone-4"><i class="bi bi-buildings"></i></span><h3>Nessuna associazione</h3><p class="mb-0">Creane una o attiva l'iscrizione autonoma.</p></div></div>
@else
    <div class="assoc-grid mb-4">
        @foreach ($associazioni as $assoc)
            <article class="card assoc-card">
                <div class="d-flex align-items-start gap-3">
                    @if ($assoc->logoUrl())
                        <img src="{{ $assoc->logoUrl('miniatura') }}" alt="Logo {{ $assoc->nome }}" class="assoc-logo">
                    @else
                        <span class="avatar avatar-lg avatar-square tone-{{ $loop->index % 6 }}">{{ $iniziali($assoc->nome) }}</span>
                    @endif
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-body text-truncate">{{ $assoc->nome }}</div>
                        <div class="who-sub text-truncate">{{ collect([$assoc->citta, $assoc->email])->filter()->join(' · ') }}</div>
                    </div>
                    <span class="badge {{ $assoc->attiva ? 'bg-success' : 'bg-secondary' }}">{{ $assoc->attiva ? 'Attiva' : 'Disattiva' }}</span>
                </div>
                <div class="assoc-stats">
                    <div><span>Soci</span><b>{{ number_format($assoc->soci_count, 0, ',', '.') }}</b></div>
                    <div><span>Admin</span><b>{{ $adminPerAssociazione[$assoc->id] ?? 0 }}</b></div>
                    <div><span>Sedi</span><b>{{ $assoc->sedi_count }}</b></div>
                    <div><span>Incassi</span><b>{{ isset($incassiPerAssociazione[$assoc->id]) ? $euroBreve((float) $incassiPerAssociazione[$assoc->id]) : '—' }}</b></div>
                </div>
                <div class="d-flex align-items-center gap-2 mt-auto">
                    <span class="mono small text-muted text-truncate flex-grow-1">/a/{{ $assoc->slug }}</span>
                    <a class="btn btn-sm btn-icon btn-ghost" href="{{ route('associazioni.index', ['edit' => $assoc->id]) }}" title="Modifica" aria-label="Modifica {{ $assoc->nome }}"><i class="bi bi-pencil"></i></a>
                    <button class="btn btn-sm btn-icon btn-ghost btn-ghost-danger" type="button" data-bs-toggle="modal" data-bs-target="#modalElimina"
                            data-assoc-id="{{ $assoc->id }}" data-assoc-nome="{{ $assoc->nome }}" title="Elimina" aria-label="Elimina {{ $assoc->nome }}"><i class="bi bi-trash"></i></button>
                    <form method="POST" action="{{ route('tenant.switch') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="associazione_id" value="{{ $assoc->id }}">
                        <button type="submit" class="btn btn-sm btn-soft">Apri <i class="bi bi-arrow-right"></i></button>
                    </form>
                </div>
            </article>
        @endforeach
    </div>
    <p class="text-muted small">Totale: {{ $associazioni->count() }} associazioni</p>
@endif

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-head pb-3">
                <div>
                    <h2>Ruoli e permessi</h2>
                    <div class="sub">Permessi predefiniti di ogni ruolo; ogni associazione assegna i ruoli ai suoi utenti.</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle matrix">
                    <thead>
                        <tr>
                            <th>Modulo</th>
                            @foreach ($ruoliMatrice as $ruolo)
                                <th class="text-center">{{ \App\Support\RuoliAssociazione::label($ruolo) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moduliMatrice as $modulo => $nomeModulo)
                            <tr>
                                <td>{{ $nomeModulo }}</td>
                                @foreach ($ruoliMatrice as $ruolo)
                                    @php $livello = $matrice[$modulo][$ruolo] ?? null; @endphp
                                    <td class="text-center">
                                        @if ($livello)
                                            <span class="badge {{ $tonoMatrice[$livello] }}">{{ $livello }}</span>
                                        @else
                                            <span class="text-subtle">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-head pb-3">
                <h2>Registro accessi</h2>
                @if (Route::has('audit-accessi.index'))
                    <a href="{{ route('audit-accessi.index') }}">Audit completo</a>
                @endif
            </div>
            @if ($accessiRecenti->isEmpty())
                <div class="panel-empty"><i class="bi bi-shield-check"></i>Nessun accesso registrato.</div>
            @else
                <ul class="panel-list">
                    @foreach ($accessiRecenti as $accesso)
                        <li>
                            <div class="who-cell">
                                <span class="avatar avatar-sm {{ $accesso->esito === \App\Models\Accesso::ESITO_FALLITO ? 'tone-danger' : 'tone-'.(abs(crc32((string) $accesso->email_tentata)) % 6) }}">{{ mb_strtoupper(mb_substr((string) $accesso->email_tentata, 0, 2)) }}</span>
                                <div class="min-w-0">
                                    <div class="text-body text-truncate">
                                        <b>{{ $accesso->email_tentata ?: 'Utente' }}</b>
                                        {{ match ($accesso->esito) { \App\Models\Accesso::ESITO_LOGIN => 'ha effettuato l\'accesso', \App\Models\Accesso::ESITO_LOGOUT => 'è uscito', default => 'accesso non riuscito' } }}
                                    </div>
                                    <div class="who-sub">{{ $accesso->associazione?->nome ?? 'Piattaforma' }} · {{ $accesso->created_at?->locale('it')->diffForHumans() }}</div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

{{-- Modale crea/modifica --}}
<div class="modal fade" id="modalAssociazione" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data"
                  action="{{ $editAssociazione ? route('associazioni.update', $editAssociazione) : route('associazioni.store') }}">
                @csrf
                @if ($editAssociazione)
                    @method('PUT')
                @endif
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editAssociazione ? 'Modifica Associazione' : 'Nuova Associazione' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <strong>Associazione non salvata.</strong> Correggi questi campi:
                            <ul class="mb-0 mt-1">
                                @foreach ($errors->all() as $messaggio)
                                    <li>{{ $messaggio }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @php
                        $formAssociazione = new \App\Forms\AssociazioneForm($editAssociazione ?? \App\Models\Associazione::class);
                    @endphp
                    <h6 class="text-muted text-uppercase small">Anagrafica</h6>
                    @include('formello.campi', ['form' => $formAssociazione, 'solo' => ['nome', 'email', 'slug']])
                    {{-- Anteprima dell'URL del mini-sito (f.22), aggiornata mentre si scrive lo slug --}}
                    <div class="form-text mt-n2 mb-3">
                        Anteprima: <span id="slugAnteprima" data-base="{{ url('/a') }}/" data-attuale="{{ $editAssociazione?->slug ?: '...' }}">{{ url('/a') }}/{{ old('slug', $editAssociazione?->slug) ?: '...' }}</span>
                    </div>
                    @include('formello.campi', ['form' => $formAssociazione, 'solo' => array_values(array_diff(\App\Forms\AssociazioneForm::GRUPPO_ANAGRAFICA, ['nome', 'email', 'slug']))])

                    <h6 class="text-muted text-uppercase small">Configurazione</h6>
                    @include('formello.campi', ['form' => $formAssociazione, 'solo' => \App\Forms\AssociazioneForm::GRUPPO_CONFIGURAZIONE])
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="logo_file">Logo (JPG/PNG, max 2 MB)</label>
                            <input type="file" class="form-control" id="logo_file" name="logo_file" accept=".jpg,.jpeg,.png">
                        </div>
                    </div>

                    @if ($editAssociazione)
                        <h6 class="text-muted text-uppercase small">Amministratori correnti</h6>
                        <ul class="list-group mb-3">
                            @forelse ($adminCorrenti as $admin)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>{{ $admin->nome_completo }} <small class="text-muted">({{ $admin->email }})</small></span>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-rimuovi-admin"
                                            data-utente-id="{{ $admin->id }}" title="Rimuovi dall'associazione">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </li>
                            @empty
                                <li class="list-group-item text-muted">Nessun amministratore collegato.</li>
                            @endforelse
                        </ul>
                    @endif

                    <h6 class="text-muted text-uppercase small">Amministratore</h6>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input switch-esclusivo" type="checkbox" id="switch_link_admin" data-target="#blocco_link_admin" data-other="#switch_create_admin">
                            <label class="form-check-label" for="switch_link_admin">Collega un admin esistente</label>
                        </div>
                        <div id="blocco_link_admin" class="mt-2 d-none">
                            <input type="text" class="form-control" id="admin_search" placeholder="Cerca per nome o email (min 3 caratteri)..." autocomplete="off">
                            <input type="hidden" name="link_admin_user_id" id="link_admin_user_id" value="{{ old('link_admin_user_id') }}">
                            <div class="list-group position-relative" id="admin_search_results"></div>
                        </div>

                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input switch-esclusivo" type="checkbox" id="switch_create_admin" name="create_admin" value="1"
                                   data-target="#blocco_create_admin" data-other="#switch_link_admin" @checked(old('create_admin'))>
                            <label class="form-check-label" for="switch_create_admin">Crea un nuovo admin per questa associazione</label>
                        </div>
                        <div id="blocco_create_admin" class="mt-2 {{ old('create_admin') ? '' : 'd-none' }}">
                            @include('formello.campi', ['form' => $formAssociazione, 'solo' => \App\Forms\AssociazioneForm::GRUPPO_ADMIN])
                        </div>
                    </div>

                    @unless ($editAssociazione)
                        <h6 class="text-muted text-uppercase small">Sede iniziale</h6>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="create_sede" name="create_sede" value="1"
                                       data-target="#blocco_sede" @checked(old('create_sede', true))>
                                <label class="form-check-label" for="create_sede">Crea la sede principale</label>
                            </div>
                            <div id="blocco_sede" class="mt-2 row g-2">
                                <div class="col-md-3"><input type="text" class="form-control" name="sede_nome" placeholder="Nome sede" value="{{ old('sede_nome', 'Sede Principale') }}"></div>
                                <div class="col-md-4"><input type="text" class="form-control" name="sede_indirizzo" placeholder="Indirizzo" value="{{ old('sede_indirizzo') }}"></div>
                                <div class="col-md-2"><input type="text" class="form-control" name="sede_citta" placeholder="Città" value="{{ old('sede_citta') }}"></div>
                                <div class="col-md-3"><input type="text" class="form-control" name="sede_responsabile" placeholder="Responsabile" value="{{ old('sede_responsabile') }}"></div>
                            </div>
                        </div>
                    @endunless

                    <h6 class="text-muted text-uppercase small">Clona configurazioni</h6>
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="clone_enable" name="clone_enable" value="1"
                                   data-target="#blocco_clone" @checked(old('clone_enable'))>
                            <label class="form-check-label" for="clone_enable">Clona configurazioni da un'altra associazione</label>
                        </div>
                        <div id="blocco_clone" class="mt-2 {{ old('clone_enable') ? '' : 'd-none' }}">
                            <select class="form-select mb-2" name="clone_from_assoc_id">
                                <option value="">-- Associazione sorgente --</option>
                                @foreach ($associazioni as $sorgente)
                                    @continue($editAssociazione && $sorgente->id === $editAssociazione->id)
                                    <option value="{{ $sorgente->id }}" @selected(old('clone_from_assoc_id') === $sorgente->id)>{{ $sorgente->nome }}</option>
                                @endforeach
                            </select>
                            <div class="row">
                                @foreach (['clone_tipi' => 'Tipi socio', 'clone_categorie' => 'Categorie socio', 'clone_sedi' => 'Sedi', 'clone_campi' => 'Campi personalizzati', 'clone_tags' => 'Tag', 'clone_gruppi' => 'Gruppi dinamici', 'clone_templates' => 'Template tessera'] as $flag => $label)
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="{{ $flag }}" name="{{ $flag }}" value="1" @checked(old($flag, true))>
                                            <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-muted small mb-0 mt-1"><i class="bi bi-info-circle me-1"></i>La clonazione non copia mai utenti/admin né soci.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">{{ $editAssociazione ? 'Salva modifiche' : 'Crea associazione' }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Form nascosto per la rimozione admin (azione esclusiva — spec §1.5) --}}
@if ($editAssociazione)
    <form method="POST" action="{{ route('associazioni.remove-admin', $editAssociazione) }}" id="formRimuoviAdmin" class="d-none">
        @csrf
        <input type="hidden" name="utente_id" id="rimuovi_utente_id">
    </form>
@endif

{{-- Modale eliminazione con conferma forte (digitare il nome) --}}
<div class="modal fade" id="modalElimina" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formElimina" action="#">
                @csrf
                @method('DELETE')
                <div class="modal-header">
                    <h5 class="modal-title text-danger">Elimina associazione</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        <strong>Attenzione:</strong> tutti i dati collegati (soci, sedi, tessere, quote, eventi, documenti...)
                        saranno rimossi definitivamente.
                    </p>
                    <label class="form-label" for="conferma_nome">Per confermare digita il nome dell'associazione: <strong id="eliminaNome"></strong></label>
                    <input type="text" class="form-control" id="conferma_nome" name="conferma_nome" required autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger">Elimina definitivamente</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Anteprima URL del mini-sito mentre si modifica lo slug
    var campoSlug = document.getElementById('slug');
    var anteprimaSlug = document.getElementById('slugAnteprima');
    if (campoSlug && anteprimaSlug) {
        campoSlug.addEventListener('input', function () {
            anteprimaSlug.textContent = anteprimaSlug.dataset.base + (campoSlug.value || anteprimaSlug.dataset.attuale);
        });
    }

    // Apertura automatica della modale in modalità edit (?edit=<id>) o dopo errori di validazione
    @if ($editAssociazione || $errors->any())
        new bootstrap.Modal(document.getElementById('modalAssociazione')).show();
    @endif

    // Switch "collega admin" / "crea admin" mutuamente esclusivi (spec §1.3.3)
    document.querySelectorAll('.switch-esclusivo').forEach(function (sw) {
        sw.addEventListener('change', function () {
            var target = document.querySelector(sw.dataset.target);
            if (target) target.classList.toggle('d-none', !sw.checked);
            if (sw.checked && sw.dataset.other) {
                var other = document.querySelector(sw.dataset.other);
                if (other && other.checked) { other.checked = false; other.dispatchEvent(new Event('change')); }
            }
        });
    });

    document.querySelectorAll('#create_sede, #clone_enable').forEach(function (sw) {
        sw.addEventListener('change', function () {
            var target = document.querySelector(sw.dataset.target);
            if (target) target.classList.toggle('d-none', !sw.checked);
        });
    });

    // Autocomplete ricerca admin disponibili (debounce 300ms, min 3 caratteri — spec §1.7)
    var searchInput = document.getElementById('admin_search');
    var results = document.getElementById('admin_search_results');
    var hiddenId = document.getElementById('link_admin_user_id');
    var debounceTimer = null;

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var q = searchInput.value.trim();
            hiddenId.value = '';
            if (q.length < 3) { results.innerHTML = ''; return; }

            debounceTimer = setTimeout(function () {
                fetch('{{ route('utenti.search') }}?available=1&q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        results.innerHTML = '';
                        (data.items || []).forEach(function (item) {
                            var a = document.createElement('button');
                            a.type = 'button';
                            a.className = 'list-group-item list-group-item-action';
                            a.textContent = item.nome + ' (' + item.email + ')';
                            a.addEventListener('click', function () {
                                hiddenId.value = item.id;
                                searchInput.value = item.nome + ' (' + item.email + ')';
                                results.innerHTML = '';
                            });
                            results.appendChild(a);
                        });
                    })
                    .catch(function () { results.innerHTML = ''; });
            }, 300);
        });
    }

    // Rimozione admin dall'associazione (azione esclusiva)
    document.querySelectorAll('.btn-rimuovi-admin').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Rimuovere questo amministratore dall\'associazione?')) return;
            document.getElementById('rimuovi_utente_id').value = btn.dataset.utenteId;
            document.getElementById('formRimuoviAdmin').submit();
        });
    });

    // Modale eliminazione: precompila azione e nome
    var modalElimina = document.getElementById('modalElimina');
    modalElimina.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        document.getElementById('formElimina').action = '{{ url('associazioni') }}/' + btn.dataset.assocId;
        document.getElementById('eliminaNome').textContent = btn.dataset.assocNome;
        document.getElementById('conferma_nome').value = '';
    });
});
</script>
@endpush
