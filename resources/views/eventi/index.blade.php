@extends('layouts.app')

@section('title', 'Eventi - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-calendar-event me-2"></i>Eventi</h1>
            <p class="text-muted mb-0">Gestione eventi di {{ session('associazione_nome', 'Manager') }}</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#eventoModal" id="btnNuovoEvento">
            <i class="bi bi-plus-lg me-1"></i>Nuovo Evento
        </button>
    </div>

    <form method="GET" action="{{ route('eventi.index') }}" class="row g-2 mb-3">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="{{ $search }}" class="form-control"
                       placeholder="Cerca per titolo, descrizione o luogo...">
                <button type="submit" class="btn btn-outline-primary">Cerca</button>
                @if ($search !== '')
                    <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary" title="Azzera ricerca">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Titolo</th>
                            <th>Data e Ora</th>
                            <th>Luogo</th>
                            <th class="text-center">Tessere create</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($eventi as $evento)
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $evento->titolo }}</span>
                                    @if ($evento->descrizione)
                                        <div class="text-muted small text-truncate" style="max-width: 300px;">{{ $evento->descrizione }}</div>
                                    @endif
                                </td>
                                <td>{{ $evento->data_evento?->format('d/m/Y H:i') }}</td>
                                <td>{{ $evento->luogo ?: '-' }}</td>
                                <td class="text-center">
                                    @if ($evento->tessere_create_count > 0)
                                        <span class="badge bg-primary">{{ $evento->tessere_create_count }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="{{ route('eventi.partecipanti.index', $evento) }}" class="btn btn-outline-primary" title="Partecipanti">
                                            <i class="bi bi-people"></i>
                                        </a>
                                        <a href="{{ route('eventi.index', array_filter(['edit' => $evento->id, 'search' => $search])) }}"
                                           class="btn btn-outline-secondary" title="Modifica">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" action="{{ route('eventi.destroy', $evento) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare questo evento?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Elimina"
                                                    style="border-top-left-radius:0;border-bottom-left-radius:0;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x d-block mb-2" style="font-size: 2rem;"></i>
                                    @if ($search !== '')
                                        Nessun evento corrisponde alla ricerca.
                                    @else
                                        Nessun evento presente. Crea il primo evento!
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($eventi->hasPages())
            <div class="card-footer bg-white">
                {{ $eventi->links() }}
            </div>
        @endif
    </div>

    {{-- Modale crea/modifica evento --}}
    <div class="modal fade" id="eventoModal" tabindex="-1" aria-labelledby="eventoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST"
                  action="{{ $editEvento ? route('eventi.update', $editEvento) : route('eventi.store') }}"
                  class="modal-content">
                @csrf
                @if ($editEvento)
                    @method('PUT')
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="eventoModalLabel">
                        {{ $editEvento ? 'Modifica Evento' : 'Nuovo Evento' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="titolo" class="form-label">Titolo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="titolo" name="titolo"
                               value="{{ old('titolo', $editEvento?->titolo) }}" required maxlength="255">
                        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="data_evento" class="form-label">Data e Ora <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control @error('data_evento') is-invalid @enderror"
                               id="data_evento" name="data_evento"
                               value="{{ old('data_evento', $editEvento?->data_evento?->format('Y-m-d\TH:i')) }}" required>
                        @error('data_evento')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="luogo" class="form-label">Luogo</label>
                        <input type="text" class="form-control @error('luogo') is-invalid @enderror" id="luogo" name="luogo"
                               value="{{ old('luogo', $editEvento?->luogo) }}" maxlength="255">
                        @error('luogo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control @error('descrizione') is-invalid @enderror" id="descrizione"
                                  name="descrizione" rows="3">{{ old('descrizione', $editEvento?->descrizione) }}</textarea>
                        @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>{{ $editEvento ? 'Aggiorna' : 'Crea' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('eventoModal');

            // Modale auto-aperta quando si arriva con ?edit=... o dopo un errore di validazione
            @if ($editEvento || $errors->any())
                new bootstrap.Modal(modalEl).show();
            @endif

            // Chiudendo la modale di modifica si torna alla lista "pulita"
            @if ($editEvento)
                modalEl.addEventListener('hidden.bs.modal', function () {
                    window.location.href = @json(route('eventi.index', array_filter(['search' => $search])));
                });
            @endif
        });
    </script>
@endpush
