@extends('layouts.app')

@section('title', 'Utenti Amministratori')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-person-badge me-2"></i>Utenti Amministratori</h1>
    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalAmministratore">
        <i class="bi bi-plus-lg me-1"></i>Nuovo Utente
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Ruolo</th>
                    <th>Associazione</th>
                    <th>Stato</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($utenti as $u)
                    <tr>
                        <td><strong>{{ $u->nome_completo }}</strong></td>
                        <td>{{ $u->email }}</td>
                        <td>
                            @if ($u->isSuperAdmin())
                                <span class="badge bg-danger">Super Admin</span>
                            @elseif ($u->isOperatore())
                                <span class="badge bg-secondary">Operatore</span>
                            @else
                                <span class="badge bg-primary">Admin Associazione</span>
                            @endif
                        </td>
                        <td>
                            @if ($u->associazione)
                                <span class="badge bg-info text-dark">{{ $u->associazione->nome }}</span>
                            @else
                                <span class="text-muted">Tutte le associazioni</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $u->attivo ? 'bg-success' : 'bg-secondary' }}">{{ $u->attivo ? 'Attivo' : 'Disattivo' }}</span>
                        </td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('amministratori.reinvia-invito', $u) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit" title="Reinvia invito">
                                    <i class="bi bi-envelope"></i>
                                </button>
                            </form>
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('amministratori.index', ['edit' => $u->id]) }}" title="Modifica">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('amministratori.destroy', $u) }}" class="d-inline"
                                  onsubmit="return confirm('Eliminare definitivamente questo utente?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Elimina">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Nessun utente presente.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Modale crea/modifica utente --}}
<div class="modal fade" id="modalAmministratore" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ $editUtente ? route('amministratori.update', $editUtente) : route('amministratori.store') }}">
                @csrf
                @if ($editUtente)
                    @method('PUT')
                @endif
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editUtente ? 'Modifica Utente' : 'Nuovo Utente' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="adm_nome">Nome *</label>
                            <input type="text" class="form-control" id="adm_nome" name="nome" required maxlength="100"
                                   value="{{ old('nome', $editUtente?->nome) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="adm_cognome">Cognome</label>
                            <input type="text" class="form-control" id="adm_cognome" name="cognome" maxlength="100"
                                   value="{{ old('cognome', $editUtente?->cognome) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="adm_email">Email *</label>
                            <input type="email" class="form-control" id="adm_email" name="email" required
                                   value="{{ old('email', $editUtente?->email) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="adm_ruolo">Ruolo *</label>
                            <select class="form-select" id="adm_ruolo" name="ruolo" required>
                                @foreach ($ruoli as $ruolo)
                                    <option value="{{ $ruolo }}" @selected(old('ruolo', $editUtente?->ruolo) === $ruolo)>
                                        {{ ['super_admin' => 'Super Admin', 'admin_associazione' => 'Admin Associazione', 'operatore' => 'Operatore'][$ruolo] ?? $ruolo }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6" id="campoAssociazione">
                            <label class="form-label" for="adm_associazione">Associazione *</label>
                            <select class="form-select" id="adm_associazione" name="associazione_id">
                                <option value="">-- Seleziona --</option>
                                @foreach ($associazioni as $assoc)
                                    <option value="{{ $assoc->id }}" @selected(old('associazione_id', $editUtente?->associazione_id) === $assoc->id)>{{ $assoc->nome }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Obbligatoria per admin di associazione e operatori; i super admin non sono legati a un'associazione.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="adm_password">Password {{ $editUtente ? '(vuota = invariata)' : '' }}</label>
                            <input type="password" class="form-control" id="adm_password" name="password" minlength="8" autocomplete="new-password">
                        </div>
                        @unless ($editUtente)
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="invia_invito" name="invia_invito" value="1" @checked(old('invia_invito'))>
                                    <label class="form-check-label" for="invia_invito">
                                        Invia invito via email (l'utente imposterà la propria password — la password qui sopra diventa facoltativa)
                                    </label>
                                </div>
                            </div>
                        @else
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="attivo" value="0">
                                    <input class="form-check-input" type="checkbox" id="adm_attivo" name="attivo" value="1"
                                           @checked(old('attivo', $editUtente->attivo))>
                                    <label class="form-check-label" for="adm_attivo">Account attivo</label>
                                </div>
                            </div>
                        @endunless
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">{{ $editUtente ? 'Salva modifiche' : 'Crea utente' }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if ($editUtente || $errors->any())
        new bootstrap.Modal(document.getElementById('modalAmministratore')).show();
    @endif

    // Il campo associazione è visibile/required solo per ruoli diversi da super_admin (spec §3.3)
    var ruolo = document.getElementById('adm_ruolo');
    var campoAssoc = document.getElementById('campoAssociazione');
    var selectAssoc = document.getElementById('adm_associazione');

    function aggiornaCampoAssociazione() {
        var isSuper = ruolo.value === 'super_admin';
        campoAssoc.classList.toggle('d-none', isSuper);
        selectAssoc.required = !isSuper;
        if (isSuper) selectAssoc.value = '';
    }

    ruolo.addEventListener('change', aggiornaCampoAssociazione);
    aggiornaCampoAssociazione();
});
</script>
@endpush
