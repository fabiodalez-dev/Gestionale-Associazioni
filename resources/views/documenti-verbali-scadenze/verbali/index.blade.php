@extends('layouts.app')

@section('title', 'Verbali')

@php
    /** @var \App\Models\Utente $utenteCorrente */
    $utenteCorrente = auth()->user();
    $isAdmin = $utenteCorrente->isSuperAdmin() || $utenteCorrente->isAdminAssociazione();
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-journal-text me-2"></i>Verbali</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        @if ($isAdmin)
        <a href="{{ route('verbali.cestino') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-trash3 me-1"></i>Cestino
        </a>
        @endif
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#verbaleModal">
            <i class="bi bi-plus-lg me-1"></i>Nuovo Verbale
        </button>
    </div>
</div>

{{-- Filtri unificati (ricerca + tipo nella stessa query string) --}}
<form method="GET" action="{{ route('verbali.index') }}" class="row g-2 mb-3">
    <div class="col-md-5">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="search" value="{{ $search }}"
                   placeholder="Cerca per titolo o partecipanti...">
        </div>
    </div>
    <div class="col-md-4">
        <select class="form-select" name="type" onchange="this.form.submit()">
            <option value="all" @selected($type === 'all' || $type === '')>Tutti i tipi</option>
            @foreach ($tipiRiunione as $tipo)
                <option value="{{ $tipo }}" @selected($type === $tipo)>{{ $tipo }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-outline-primary">Filtra</button>
        <a href="{{ route('verbali.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="card">
    <div class="card-header">{{ $verbali->total() }} {{ $verbali->total() === 1 ? 'verbale trovato' : 'verbali trovati' }}</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Titolo</th>
                    <th>Data Riunione</th>
                    <th>Tipo</th>
                    <th>Stato</th>
                    <th>Firme</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($verbali as $verbale)
                    <tr>
                        <td>
                            <strong>{{ $verbale->titolo }}</strong>
                            @if ($verbale->presenti)
                                <br><small class="text-muted">Partecipanti: {{ \Illuminate\Support\Str::limit($verbale->presenti, 50) }}</small>
                            @endif
                        </td>
                        <td>{{ $verbale->data_riunione->format('d/m/Y') }}</td>
                        <td><span class="badge bg-info text-dark">{{ $verbale->tipo_riunione }}</span></td>
                        <td>
                            @if ($verbale->approvato)
                                <span class="badge bg-success">Approvato</span>
                                <br><small class="text-muted">
                                    da {{ $verbale->approvatoDa?->nome_completo ?? 'N/D' }}
                                    @if ($verbale->data_approvazione) il {{ $verbale->data_approvazione->format('d/m/Y') }} @endif
                                </small>
                            @else
                                <span class="badge bg-warning text-dark">In attesa</span>
                            @endif
                        </td>
                        <td>
                            @php $firmati = $verbale->firmatari->whereNotNull('firmato_at')->count(); @endphp
                            @if ($verbale->firmatari->isNotEmpty())
                                <span class="badge bg-secondary">{{ $firmati }}/{{ $verbale->firmatari->count() }}</span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                    data-bs-target="#viewModal{{ $verbale->id }}" title="Visualizza">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="{{ route('verbali.pdf', $verbale) }}" class="btn btn-sm btn-outline-primary" title="Esporta PDF">
                                <i class="bi bi-file-pdf"></i>
                            </a>
                            @can('update', $verbale)
                            <a href="{{ route('verbali.index', array_merge(request()->query(), ['edit' => $verbale->id])) }}"
                               class="btn btn-sm btn-outline-warning" title="Modifica">
                                <i class="bi bi-pencil"></i>
                            </a>
                            @endcan
                            @if (! $verbale->approvato)
                                @can('approve', $verbale)
                                <form method="POST" action="{{ route('verbali.approva', $verbale) }}" class="d-inline"
                                      onsubmit="return confirm('Approvare questo verbale?');">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Approva">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                @endcan
                            @endif
                            @can('delete', $verbale)
                            <form method="POST" action="{{ route('verbali.destroy', $verbale) }}" class="d-inline"
                                  onsubmit="return confirm('Sei sicuro di voler eliminare questo verbale?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>Nessun verbale trovato.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($verbali->hasPages())
        <div class="card-footer">
            {{ $verbali->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>

{{-- Modal visualizzazione (uno per verbale, pattern legacy) --}}
@foreach ($verbali as $verbale)
<div class="modal fade" id="viewModal{{ $verbale->id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ $verbale->titolo }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-2">
                    <div class="col-md-6"><strong>Data Riunione:</strong> {{ $verbale->data_riunione->format('d/m/Y') }}</div>
                    <div class="col-md-6"><strong>Tipo:</strong> {{ $verbale->tipo_riunione }}</div>
                </div>
                @if ($verbale->presenti)
                    <div class="mb-2"><strong>Partecipanti:</strong><br>{!! nl2br(e($verbale->presenti)) !!}</div>
                @endif
                @if ($verbale->ordine_del_giorno)
                    <div class="mb-2"><strong>Ordine del Giorno:</strong><br>{!! nl2br(e($verbale->ordine_del_giorno)) !!}</div>
                @endif
                @if ($verbale->deliberazioni)
                    <div class="mb-2"><strong>Deliberazioni:</strong><br>{!! nl2br(e($verbale->deliberazioni)) !!}</div>
                @endif

                <hr>
                <h6><i class="bi bi-pen me-1"></i>Firmatari</h6>
                @if ($verbale->firmatari->isEmpty())
                    <p class="text-muted small mb-2">Nessun firmatario registrato.</p>
                @else
                    <ul class="list-group mb-3">
                        @foreach ($verbale->firmatari as $firmatario)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    {{ $firmatario->nominativo }}
                                    @if ($firmatario->ruolo)<span class="badge bg-light text-dark border ms-1">{{ $firmatario->ruolo }}</span>@endif
                                    @if ($firmatario->firmato_at)
                                        <span class="badge bg-success ms-1">Firmato il {{ $firmatario->firmato_at->format('d/m/Y H:i') }}</span>
                                    @else
                                        <span class="badge bg-warning text-dark ms-1">Da firmare</span>
                                    @endif
                                </span>
                                @can('update', $verbale)
                                <span>
                                    @if (! $firmatario->firmato_at)
                                    <form method="POST" action="{{ route('verbali.firmatari.firma', [$verbale, $firmatario]) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Registra firma">
                                            <i class="bi bi-pen"></i>
                                        </button>
                                    </form>
                                    @endif
                                    <form method="POST" action="{{ route('verbali.firmatari.destroy', [$verbale, $firmatario]) }}" class="d-inline"
                                          onsubmit="return confirm('Rimuovere questo firmatario?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Rimuovi">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </span>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif

                @can('update', $verbale)
                <form method="POST" action="{{ route('verbali.firmatari.store', $verbale) }}" class="row g-2">
                    @csrf
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" name="socio_id">
                            <option value="">Socio...</option>
                            @foreach ($soci as $socio)
                                <option value="{{ $socio->id }}">{{ $socio->cognome }} {{ $socio->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" class="form-control form-control-sm" name="nome" placeholder="o nominativo libero">
                    </div>
                    <div class="col-md-3">
                        <input type="text" class="form-control form-control-sm" name="ruolo" placeholder="Ruolo (es. Presidente)">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">Aggiungi</button>
                    </div>
                </form>
                @endcan
            </div>
            <div class="modal-footer">
                <a href="{{ route('verbali.pdf', $verbale) }}" class="btn btn-outline-primary">
                    <i class="bi bi-file-pdf me-1"></i>Esporta PDF
                </a>
                <button type="button" class="btn btn-secondary" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Stampa
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>
@endforeach

{{-- Modal create/edit (pattern legacy: ?edit=<uuid> precompila e auto-apre) --}}
<div class="modal fade" id="verbaleModal" tabindex="-1" aria-labelledby="verbaleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST"
                  action="{{ $verbaleInModifica ? route('verbali.update', $verbaleInModifica) : route('verbali.store') }}">
                @csrf
                @if ($verbaleInModifica)
                    @method('PUT')
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="verbaleModalLabel">
                        {{ $verbaleInModifica ? 'Modifica Verbale' : 'Nuovo Verbale' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    @if ($verbaleInModifica?->approvato)
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Questo verbale è approvato: salvando le modifiche l'approvazione sarà azzerata.
                        </div>
                    @endif
                    <div class="mb-3">
                        <label for="titolo" class="form-label">Titolo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="titolo" name="titolo"
                               value="{{ old('titolo', $verbaleInModifica?->titolo) }}" required>
                        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="data_riunione" class="form-label">Data Riunione <span class="text-danger">*</span></label>
                            <input type="date" class="form-control @error('data_riunione') is-invalid @enderror"
                                   id="data_riunione" name="data_riunione"
                                   value="{{ old('data_riunione', $verbaleInModifica?->data_riunione?->format('Y-m-d')) }}" required>
                            @error('data_riunione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="tipo_riunione" class="form-label">Tipo Riunione <span class="text-danger">*</span></label>
                            <select class="form-select @error('tipo_riunione') is-invalid @enderror" id="tipo_riunione" name="tipo_riunione" required>
                                @foreach ($tipiRiunione as $tipo)
                                    <option value="{{ $tipo }}" @selected(old('tipo_riunione', $verbaleInModifica?->tipo_riunione) === $tipo)>{{ $tipo }}</option>
                                @endforeach
                            </select>
                            @error('tipo_riunione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="presenti" class="form-label">Presenti</label>
                        <textarea class="form-control @error('presenti') is-invalid @enderror" id="presenti" name="presenti"
                                  rows="2" placeholder="Elenco dei partecipanti...">{{ old('presenti', $verbaleInModifica?->presenti) }}</textarea>
                        @error('presenti')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="ordine_del_giorno" class="form-label">Ordine del Giorno</label>
                        <textarea class="form-control @error('ordine_del_giorno') is-invalid @enderror" id="ordine_del_giorno"
                                  name="ordine_del_giorno" rows="3">{{ old('ordine_del_giorno', $verbaleInModifica?->ordine_del_giorno) }}</textarea>
                        @error('ordine_del_giorno')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="deliberazioni" class="form-label">Deliberazioni</label>
                        <textarea class="form-control @error('deliberazioni') is-invalid @enderror" id="deliberazioni"
                                  name="deliberazioni" rows="3">{{ old('deliberazioni', $verbaleInModifica?->deliberazioni) }}</textarea>
                        @error('deliberazioni')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>{{ $verbaleInModifica ? 'Aggiorna' : 'Salva' }}
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
        // Edit flow legacy: il modal si auto-apre con il form precompilato
        @if ($verbaleInModifica || $errors->any())
            new bootstrap.Modal(document.getElementById('verbaleModal')).show();
        @endif
    });
</script>
@endpush
