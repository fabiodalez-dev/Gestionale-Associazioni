@extends('layouts.app')

@section('title', 'Scadenze')

@php
    $badgePriorita = ['Critica' => 'danger', 'Alta' => 'warning text-dark', 'Media' => 'info text-dark', 'Bassa' => 'secondary'];
    $badgeStato = ['Attiva' => 'success', 'Completata' => 'primary', 'Annullata' => 'dark'];
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-alarm me-2"></i>Scadenze</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="{{ route('scadenze.esporta-csv', request()->query()) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-filetype-csv me-1"></i>Esporta CSV
        </a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#scadenzaModal">
            <i class="bi bi-plus-lg me-1"></i>Nuova Scadenza
        </button>
    </div>
</div>

{{-- Card statistiche (sul result-set filtrato, come il legacy) --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-danger h-100">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Critiche</div>
                        <div class="fs-4 fw-bold text-danger">{{ $stats['critiche'] }}</div>
                    </div>
                    <i class="bi bi-exclamation-octagon fs-3 text-danger"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-warning h-100">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">In Scadenza (7 gg)</div>
                        <div class="fs-4 fw-bold text-warning">{{ $stats['in_scadenza'] }}</div>
                    </div>
                    <i class="bi bi-hourglass-split fs-3 text-warning"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-success h-100">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Attive</div>
                        <div class="fs-4 fw-bold text-success">{{ $stats['attive'] }}</div>
                    </div>
                    <i class="bi bi-lightning fs-3 text-success"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-primary h-100">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Completate</div>
                        <div class="fs-4 fw-bold text-primary">{{ $stats['completate'] }}</div>
                    </div>
                    <i class="bi bi-check2-circle fs-3 text-primary"></i>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filtri unificati: tutti i parametri nella stessa query string (fix del difetto legacy) --}}
<form method="GET" action="{{ route('scadenze.index') }}" class="row g-2 mb-3">
    <div class="col-md-4">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="search" value="{{ $search }}"
                   placeholder="Cerca per titolo o descrizione...">
        </div>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="status" onchange="this.form.submit()">
            <option value="all" @selected($status === 'all')>Tutti gli stati</option>
            @foreach ($stati as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="type" onchange="this.form.submit()">
            <option value="all" @selected($type === 'all')>Tutti i tipi</option>
            @foreach ($tipi as $t)
                <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="priority" onchange="this.form.submit()">
            <option value="all" @selected($priority === 'all')>Tutte le priorità</option>
            @foreach ($priorita as $p)
                <option value="{{ $p }}" @selected($priority === $p)>{{ $p }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-primary">Filtra</button>
        <a href="{{ route('scadenze.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="card">
    <div class="card-header">{{ $scadenze->total() }} {{ $scadenze->total() === 1 ? 'scadenza trovata' : 'scadenze trovate' }}</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Titolo</th>
                    <th>Data Scadenza</th>
                    <th>Tipo</th>
                    <th>Priorità</th>
                    <th>Stato</th>
                    <th>Assegnato a</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($scadenze as $scadenza)
                    @php
                        $giorni = $scadenza->giorni_residui;
                        $rowClass = '';
                        if ($scadenza->isAttiva()) {
                            if ($scadenza->isScaduta()) {
                                $rowClass = 'table-danger';
                            } elseif ($scadenza->isInScadenza()) {
                                $rowClass = 'table-warning';
                            }
                        }
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td>
                            <strong>{{ $scadenza->titolo }}</strong>
                            @if ($scadenza->descrizione)
                                <br><small class="text-muted">{{ \Illuminate\Support\Str::limit($scadenza->descrizione, 80) }}</small>
                            @endif
                        </td>
                        <td>
                            {{ $scadenza->data_scadenza->format('d/m/Y') }}
                            @if ($scadenza->isAttiva())
                                <br>
                                @if ($giorni < 0)
                                    <small class="text-danger fw-bold">Scaduta da {{ abs($giorni) }} {{ abs($giorni) === 1 ? 'giorno' : 'giorni' }}</small>
                                @elseif ($giorni === 0)
                                    <small class="text-warning fw-bold">Scade oggi</small>
                                @elseif ($giorni <= $scadenza->promemoria_giorni)
                                    <small class="text-warning fw-bold">Scade tra {{ $giorni }} {{ $giorni === 1 ? 'giorno' : 'giorni' }}</small>
                                @else
                                    <small class="text-muted">Tra {{ $giorni }} giorni</small>
                                @endif
                            @endif
                        </td>
                        <td><span class="badge bg-info text-dark">{{ $scadenza->tipo_scadenza }}</span></td>
                        <td><span class="badge bg-{{ $badgePriorita[$scadenza->priorita] ?? 'secondary' }}">{{ $scadenza->priorita }}</span></td>
                        <td>
                            <span class="badge bg-{{ $badgeStato[$scadenza->stato] ?? 'secondary' }}">{{ $scadenza->stato }}</span>
                            @if ($scadenza->stato === 'Completata' && $scadenza->completataDa)
                                <br><small class="text-muted">da {{ $scadenza->completataDa->nome_completo }}
                                @if ($scadenza->completata_at) il {{ $scadenza->completata_at->format('d/m/Y') }}@endif</small>
                            @endif
                        </td>
                        <td>
                            @if ($scadenza->assegnatoA)
                                <span class="badge bg-primary">{{ $scadenza->assegnatoA->nome_completo }}</span>
                            @else
                                <span class="text-muted">Non assegnato</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($scadenza->isAttiva())
                                <form method="POST" action="{{ route('scadenze.completa', $scadenza) }}" class="d-inline"
                                      onsubmit="return confirm('Segnare questa scadenza come completata?');">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Completa">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                            @endif
                            <a href="{{ route('scadenze.index', array_merge(request()->query(), ['edit' => $scadenza->id])) }}"
                               class="btn btn-sm btn-outline-warning" title="Modifica">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('scadenze.destroy', $scadenza) }}" class="d-inline"
                                  onsubmit="return confirm('Sei sicuro di voler eliminare questa scadenza?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>Nessuna scadenza trovata.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($scadenze->hasPages())
        <div class="card-footer">
            {{ $scadenze->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>

{{-- Modal create/edit (pattern legacy: ?edit=<uuid> precompila e auto-apre) --}}
<div class="modal fade" id="scadenzaModal" tabindex="-1" aria-labelledby="scadenzaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST"
                  action="{{ $scadenzaInModifica ? route('scadenze.update', $scadenzaInModifica) : route('scadenze.store') }}">
                @csrf
                @if ($scadenzaInModifica)
                    @method('PUT')
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="scadenzaModalLabel">
                        {{ $scadenzaInModifica ? 'Modifica Scadenza' : 'Nuova Scadenza' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="titolo" class="form-label">Titolo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="titolo" name="titolo"
                               value="{{ old('titolo', $scadenzaInModifica?->titolo) }}" required>
                        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control @error('descrizione') is-invalid @enderror" id="descrizione"
                                  name="descrizione" rows="2">{{ old('descrizione', $scadenzaInModifica?->descrizione) }}</textarea>
                        @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="data_scadenza" class="form-label">Data Scadenza <span class="text-danger">*</span></label>
                            <input type="date" class="form-control @error('data_scadenza') is-invalid @enderror"
                                   id="data_scadenza" name="data_scadenza"
                                   value="{{ old('data_scadenza', $scadenzaInModifica?->data_scadenza?->format('Y-m-d')) }}" required>
                            @error('data_scadenza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="promemoria_giorni" class="form-label">Promemoria (giorni prima) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control @error('promemoria_giorni') is-invalid @enderror"
                                   id="promemoria_giorni" name="promemoria_giorni" min="1" max="365"
                                   value="{{ old('promemoria_giorni', $scadenzaInModifica?->promemoria_giorni ?? 7) }}" required>
                            @error('promemoria_giorni')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="tipo_scadenza" class="form-label">Tipo <span class="text-danger">*</span></label>
                            <select class="form-select @error('tipo_scadenza') is-invalid @enderror" id="tipo_scadenza" name="tipo_scadenza" required>
                                @foreach ($tipi as $t)
                                    <option value="{{ $t }}" @selected(old('tipo_scadenza', $scadenzaInModifica?->tipo_scadenza) === $t)>{{ $t }}</option>
                                @endforeach
                            </select>
                            @error('tipo_scadenza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="priorita" class="form-label">Priorità <span class="text-danger">*</span></label>
                            <select class="form-select @error('priorita') is-invalid @enderror" id="priorita" name="priorita" required>
                                @foreach ($priorita as $p)
                                    <option value="{{ $p }}" @selected(old('priorita', $scadenzaInModifica?->priorita ?? 'Bassa') === $p)>{{ $p }}</option>
                                @endforeach
                            </select>
                            @error('priorita')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="stato" class="form-label">Stato <span class="text-danger">*</span></label>
                            <select class="form-select @error('stato') is-invalid @enderror" id="stato" name="stato" required>
                                @foreach ($stati as $s)
                                    <option value="{{ $s }}" @selected(old('stato', $scadenzaInModifica?->stato ?? 'Attiva') === $s)>{{ $s }}</option>
                                @endforeach
                            </select>
                            @error('stato')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="assegnato_a" class="form-label">Assegnato a</label>
                        <select class="form-select @error('assegnato_a') is-invalid @enderror" id="assegnato_a" name="assegnato_a">
                            <option value="">Non assegnato</option>
                            @foreach ($utenti as $u)
                                <option value="{{ $u->id }}" @selected(old('assegnato_a', $scadenzaInModifica?->assegnato_a) === $u->id)>
                                    {{ $u->nome_completo }} ({{ $u->email }})
                                </option>
                            @endforeach
                        </select>
                        @error('assegnato_a')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>{{ $scadenzaInModifica ? 'Aggiorna' : 'Salva' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        @if ($scadenzaInModifica || $errors->any())
            new bootstrap.Modal(document.getElementById('scadenzaModal')).show();
        @endif
    });
</script>
@endpush
