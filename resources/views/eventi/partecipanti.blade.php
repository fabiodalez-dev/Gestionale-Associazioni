@extends('layouts.app')

@section('title', 'Partecipanti - '.$evento->titolo)

@php
    $badgeClass = [
        \App\Models\EventoPartecipante::STATO_CONFERMATO => 'bg-success',
        \App\Models\EventoPartecipante::STATO_FORSE => 'bg-warning text-dark',
        \App\Models\EventoPartecipante::STATO_NON_PARTECIPA => 'bg-danger',
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('eventi.index') }}">Eventi</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Partecipanti</li>
                </ol>
            </nav>
            <h1 class="h2"><i class="bi bi-people me-2"></i>{{ $evento->titolo }}</h1>
            <p class="text-muted mb-0">
                <i class="bi bi-calendar me-1"></i>{{ $evento->data_evento?->format('d/m/Y H:i') }}
                @if ($evento->luogo)
                    <span class="ms-2"><i class="bi bi-geo-alt me-1"></i>{{ $evento->luogo }}</span>
                @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('eventi.partecipanti.export', array_filter(['evento' => $evento->id, 'status' => $status !== 'all' ? $status : null])) }}"
               class="btn btn-outline-secondary">
                <i class="bi bi-download me-1"></i>Esporta CSV
            </a>
            @if ($sociDisponibili->isNotEmpty())
                <form method="POST" action="{{ route('eventi.partecipanti.storeAll', $evento) }}" class="d-inline"
                      onsubmit="return confirm('Aggiungere tutti i soci attivi all\'evento?');">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-people-fill me-1"></i>Aggiungi Tutti i Soci
                    </button>
                </form>
            @endif
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSocioModal">
                <i class="bi bi-person-plus me-1"></i>Aggiungi Socio
            </button>
        </div>
    </div>

    {{-- Statistiche (sempre sull'intero evento, indipendenti dal filtro) --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card stat-card h-100">
                <div class="card-body py-3 text-center">
                    <div class="fs-3 fw-bold text-success">{{ $stats['confermati'] }}</div>
                    <div class="text-muted small">Confermati</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card stat-card h-100">
                <div class="card-body py-3 text-center">
                    <div class="fs-3 fw-bold text-warning">{{ $stats['forse'] }}</div>
                    <div class="text-muted small">Forse</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card stat-card h-100">
                <div class="card-body py-3 text-center">
                    <div class="fs-3 fw-bold text-danger">{{ $stats['non_partecipa'] }}</div>
                    <div class="text-muted small">Non Partecipa</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card stat-card h-100">
                <div class="card-body py-3 text-center">
                    <div class="fs-3 fw-bold">{{ $stats['totale'] }}</div>
                    <div class="text-muted small">Totale</div>
                </div>
            </div>
        </div>
        @if ($tessereCreate > 0)
            <div class="col-6 col-md-3 col-xl-2">
                <div class="card stat-card h-100 border-primary">
                    <div class="card-body py-3 text-center">
                        <div class="fs-3 fw-bold text-primary">{{ $tessereCreate }}</div>
                        <div class="text-muted small">Tessere create</div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Filtro per stato --}}
    <div class="btn-group mb-3" role="group" aria-label="Filtro stato">
        <a href="{{ route('eventi.partecipanti.index', $evento) }}"
           class="btn btn-sm {{ $status === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">Tutti</a>
        @foreach ($stati as $stato)
            <a href="{{ route('eventi.partecipanti.index', ['evento' => $evento->id, 'status' => $stato]) }}"
               class="btn btn-sm {{ $status === $stato ? 'btn-primary' : 'btn-outline-primary' }}">{{ $stato }}</a>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Socio</th>
                            <th>N. Socio</th>
                            <th>Contatti</th>
                            <th>Stato</th>
                            <th>Ultima variazione</th>
                            <th>Note</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($partecipanti as $p)
                            <tr>
                                <td class="fw-semibold">{{ $p->socio?->nome }} {{ $p->socio?->cognome }}</td>
                                <td>{{ $p->socio?->numero_socio }}</td>
                                <td>
                                    <div class="small">{{ $p->socio?->email }}</div>
                                    @if ($p->socio?->telefono)
                                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i>{{ $p->socio->telefono }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $badgeClass[$p->stato_partecipazione] ?? 'bg-secondary' }}">
                                        {{ $p->stato_partecipazione }}
                                    </span>
                                </td>
                                <td>{{ $p->data_conferma?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td>
                                    @if ($p->note)
                                        <span class="d-inline-block text-truncate" style="max-width: 150px;" title="{{ $p->note }}">{{ $p->note }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-secondary" title="Modifica partecipazione"
                                                data-bs-toggle="modal" data-bs-target="#updateModal-{{ $p->id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="{{ route('eventi.partecipanti.destroy', [$evento, $p->id]) }}" class="d-inline"
                                              onsubmit="return confirm('Rimuovere questo partecipante dall\'evento?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Rimuovi"
                                                    style="border-top-left-radius:0;border-bottom-left-radius:0;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>

                                    {{-- Modale aggiornamento partecipazione --}}
                                    <div class="modal fade" id="updateModal-{{ $p->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <form method="POST" action="{{ route('eventi.partecipanti.update', [$evento, $p->id]) }}" class="modal-content text-start">
                                                @csrf
                                                @method('PUT')
                                                <div class="modal-header">
                                                    <h5 class="modal-title">{{ $p->socio?->nome }} {{ $p->socio?->cognome }}</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label" for="stato-{{ $p->id }}">Stato partecipazione <span class="text-danger">*</span></label>
                                                        <select class="form-select" id="stato-{{ $p->id }}" name="stato_partecipazione" required>
                                                            @foreach ($stati as $stato)
                                                                <option value="{{ $stato }}" @selected($p->stato_partecipazione === $stato)>{{ $stato }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label" for="note-{{ $p->id }}">Note</label>
                                                        <textarea class="form-control" id="note-{{ $p->id }}" name="note" rows="3">{{ $p->note }}</textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                                                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Aggiorna</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-person-x d-block mb-2" style="font-size: 2rem;"></i>
                                    @if ($status !== 'all')
                                        Nessun partecipante con stato "{{ $status }}".
                                    @else
                                        Nessun partecipante iscritto a questo evento.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modale aggiunta singolo socio --}}
    <div class="modal fade" id="addSocioModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('eventi.partecipanti.store', $evento) }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Aggiungi Socio all'Evento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    @if ($sociDisponibili->isEmpty())
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Tutti i soci attivi sono già iscritti a questo evento (o non ci sono soci attivi).
                        </div>
                    @else
                        <div class="mb-3">
                            <label class="form-label" for="socio_id">Socio <span class="text-danger">*</span></label>
                            <select class="form-select @error('socio_id') is-invalid @enderror" id="socio_id" name="socio_id" required>
                                <option value="">-- Seleziona un socio --</option>
                                @foreach ($sociDisponibili as $socio)
                                    <option value="{{ $socio->id }}" @selected(old('socio_id') === $socio->id)>
                                        {{ $socio->nome }} {{ $socio->cognome }} ({{ $socio->numero_socio }})
                                    </option>
                                @endforeach
                            </select>
                            @error('socio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="stato_partecipazione">Stato partecipazione <span class="text-danger">*</span></label>
                            <select class="form-select @error('stato_partecipazione') is-invalid @enderror"
                                    id="stato_partecipazione" name="stato_partecipazione" required>
                                @foreach ($stati as $stato)
                                    <option value="{{ $stato }}" @selected(old('stato_partecipazione', \App\Models\EventoPartecipante::STATO_NON_PARTECIPA) === $stato)>
                                        {{ $stato }}
                                    </option>
                                @endforeach
                            </select>
                            @error('stato_partecipazione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="note">Note</label>
                            <textarea class="form-control" id="note" name="note" rows="3">{{ old('note') }}</textarea>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary" @disabled($sociDisponibili->isEmpty())>
                        <i class="bi bi-person-plus me-1"></i>Aggiungi
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($errors->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    new bootstrap.Modal(document.getElementById('addSocioModal')).show();
                });
            </script>
        @endpush
    @endif
@endsection
