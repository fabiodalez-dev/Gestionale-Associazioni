@extends('layouts.app')

@section('title', 'Gestione Associazioni')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-buildings me-2"></i>Gestione Associazioni</h1>
    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalAssociazione" id="btnNuovaAssociazione">
        <i class="bi bi-plus-lg me-1"></i>Nuova Associazione
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Associazione</th>
                    <th>Email</th>
                    <th>Stato</th>
                    <th class="text-center">Sedi</th>
                    <th class="text-center">Soci</th>
                    <th>Scadenza default</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($associazioni as $assoc)
                    <tr>
                        <td>
                            @if ($assoc->logo_url)
                                <img src="{{ asset('storage/'.$assoc->logo_url) }}" alt="Logo {{ $assoc->nome }}" style="height: 28px; width: auto;" class="me-2 rounded">
                            @endif
                            <strong>{{ $assoc->nome }}</strong>
                        </td>
                        <td>{{ $assoc->email }}</td>
                        <td>
                            <span class="badge {{ $assoc->attiva ? 'bg-success' : 'bg-secondary' }}">
                                {{ $assoc->attiva ? 'Attiva' : 'Disattiva' }}
                            </span>
                        </td>
                        <td class="text-center">{{ $assoc->sedi_count }}</td>
                        <td class="text-center">{{ $assoc->soci_count }}</td>
                        <td>{{ $assoc->tipo_scadenza_default === 'annuale' ? 'Annuale (365 gg)' : 'Anno solare' }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('associazioni.index', ['edit' => $assoc->id]) }}" title="Modifica">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button class="btn btn-sm btn-outline-danger" type="button"
                                    data-bs-toggle="modal" data-bs-target="#modalElimina"
                                    data-assoc-id="{{ $assoc->id }}" data-assoc-nome="{{ $assoc->nome }}" title="Elimina">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">Nessuna associazione presente.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr><td colspan="7" class="text-muted small">Totale: {{ $associazioni->count() }} associazioni</td></tr>
            </tfoot>
        </table>
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
                    <h6 class="text-muted text-uppercase small">Anagrafica</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="nome">Nome *</label>
                            <input type="text" class="form-control" id="nome" name="nome" required maxlength="255"
                                   value="{{ old('nome', $editAssociazione?->nome) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email *</label>
                            <input type="email" class="form-control" id="email" name="email" required
                                   value="{{ old('email', $editAssociazione?->email) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="partita_iva">Partita IVA</label>
                            <input type="text" class="form-control" id="partita_iva" name="partita_iva" maxlength="20"
                                   value="{{ old('partita_iva', $editAssociazione?->partita_iva) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="codice_fiscale">Codice fiscale</label>
                            <input type="text" class="form-control" id="codice_fiscale" name="codice_fiscale" maxlength="16"
                                   value="{{ old('codice_fiscale', $editAssociazione?->codice_fiscale) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="telefono">Telefono</label>
                            <input type="text" class="form-control" id="telefono" name="telefono" maxlength="20"
                                   value="{{ old('telefono', $editAssociazione?->telefono) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="indirizzo">Indirizzo</label>
                            <input type="text" class="form-control" id="indirizzo" name="indirizzo"
                                   value="{{ old('indirizzo', $editAssociazione?->indirizzo) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="citta">Città</label>
                            <input type="text" class="form-control" id="citta" name="citta" maxlength="100"
                                   value="{{ old('citta', $editAssociazione?->citta) }}">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label" for="provincia">Prov.</label>
                            <input type="text" class="form-control" id="provincia" name="provincia" maxlength="2"
                                   value="{{ old('provincia', $editAssociazione?->provincia) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="cap">CAP</label>
                            <input type="text" class="form-control" id="cap" name="cap" maxlength="5"
                                   value="{{ old('cap', $editAssociazione?->cap) }}">
                        </div>
                    </div>

                    <h6 class="text-muted text-uppercase small">Configurazione</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" for="tipo_scadenza_default">Tipo scadenza tessere</label>
                            <select class="form-select" id="tipo_scadenza_default" name="tipo_scadenza_default">
                                <option value="solare" @selected(old('tipo_scadenza_default', $editAssociazione?->tipo_scadenza_default ?? 'solare') === 'solare')>Anno Solare (31 Dicembre)</option>
                                <option value="annuale" @selected(old('tipo_scadenza_default', $editAssociazione?->tipo_scadenza_default) === 'annuale')>Annuale (365 giorni da iscrizione)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="giorni_notifica_scadenza">Giorni preavviso notifica</label>
                            <input type="number" class="form-control" id="giorni_notifica_scadenza" name="giorni_notifica_scadenza" min="1" max="365"
                                   value="{{ old('giorni_notifica_scadenza', $editAssociazione?->giorni_notifica_scadenza ?? 30) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="costo_tessera">Costo tessera (€)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="costo_tessera" name="costo_tessera"
                                   value="{{ old('costo_tessera', $editAssociazione?->costo_tessera) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="logo_file">Logo (JPG/PNG, max 2 MB)</label>
                            <input type="file" class="form-control" id="logo_file" name="logo_file" accept=".jpg,.jpeg,.png">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="attiva" name="attiva" value="1"
                                       @checked(old('attiva', $editAssociazione?->attiva ?? true))>
                                <label class="form-check-label" for="attiva">Associazione attiva</label>
                            </div>
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
                            <div class="row g-2">
                                <div class="col-md-3"><input type="text" class="form-control" name="admin_nome" placeholder="Nome" value="{{ old('admin_nome') }}"></div>
                                <div class="col-md-3"><input type="text" class="form-control" name="admin_cognome" placeholder="Cognome" value="{{ old('admin_cognome') }}"></div>
                                <div class="col-md-3"><input type="email" class="form-control" name="admin_email" placeholder="Email" value="{{ old('admin_email') }}"></div>
                                <div class="col-md-3"><input type="password" class="form-control" name="admin_password" placeholder="Password (min 8)" autocomplete="new-password"></div>
                            </div>
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
