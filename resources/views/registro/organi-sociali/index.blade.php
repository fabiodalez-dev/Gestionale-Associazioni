@extends('layouts.app')

@section('title', 'Organi sociali')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3 me-2"></i>Organi sociali</h1>
    @can('create', \App\Models\Carica::class)
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#caricaModal">
        <i class="bi bi-plus-lg me-1"></i>Nuova carica
    </button>
    @endcan
</div>

@if ($inScadenza->isNotEmpty())
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong>Mandati in scadenza nei prossimi {{ \App\Models\Carica::GIORNI_ALERT_SCADENZA }} giorni:</strong>
        <ul class="mb-0 mt-1">
            @foreach ($inScadenza as $carica)
                <li>
                    {{ $carica->ruolo }} — {{ $carica->socio?->nome_completo ?? 'Socio eliminato' }}
                    (scade il {{ $carica->fine?->format('d/m/Y') }})
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-person-badge me-1"></i>Cariche attive ({{ $attive->count() }})</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Ruolo</th>
                    <th>Socio</th>
                    <th>Inizio mandato</th>
                    <th>Fine mandato</th>
                    <th>Note</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attive as $carica)
                    <tr>
                        <td><strong>{{ $carica->ruolo }}</strong></td>
                        <td>
                            @if ($carica->socio)
                                <a href="{{ route('soci.show', $carica->socio) }}">{{ $carica->socio->cognome }} {{ $carica->socio->nome }}</a>
                            @else
                                <span class="text-muted fst-italic">Socio eliminato</span>
                            @endif
                        </td>
                        <td>{{ $carica->inizio?->format('d/m/Y') }}</td>
                        <td>
                            @if ($carica->fine)
                                {{ $carica->fine->format('d/m/Y') }}
                                @if ($inScadenza->contains('id', $carica->id))
                                    <span class="badge bg-warning text-dark ms-1">In scadenza</span>
                                @endif
                            @else
                                <span class="text-muted">In corso</span>
                            @endif
                        </td>
                        <td>{{ $carica->note ? \Illuminate\Support\Str::limit($carica->note, 60) : '—' }}</td>
                        <td class="text-end">
                            @can('update', $carica)
                            <a href="{{ route('organi-sociali.index', ['edit' => $carica->id]) }}"
                               class="btn btn-sm btn-outline-warning" title="Modifica">
                                <i class="bi bi-pencil"></i>
                            </a>
                            @endcan
                            @can('delete', $carica)
                            <form method="POST" action="{{ route('organi-sociali.destroy', $carica) }}" class="d-inline"
                                  onsubmit="return confirm('Eliminare questa carica?');">
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
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>Nessuna carica attiva.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i>Storico cariche ({{ $storiche->total() }})</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Ruolo</th>
                    <th>Socio</th>
                    <th>Inizio mandato</th>
                    <th>Fine mandato</th>
                    <th>Note</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($storiche as $carica)
                    <tr>
                        <td>{{ $carica->ruolo }}</td>
                        <td>{{ $carica->socio?->nome_completo ?? 'Socio eliminato' }}</td>
                        <td>{{ $carica->inizio?->format('d/m/Y') }}</td>
                        <td>{{ $carica->fine?->format('d/m/Y') }}</td>
                        <td>{{ $carica->note ? \Illuminate\Support\Str::limit($carica->note, 60) : '—' }}</td>
                        <td class="text-end">
                            @can('delete', $carica)
                            <form method="POST" action="{{ route('organi-sociali.destroy', $carica) }}" class="d-inline"
                                  onsubmit="return confirm('Eliminare questa carica dallo storico?');">
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
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>Nessuna carica conclusa.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($storiche->hasPages())
        <div class="card-footer">
            {{ $storiche->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>

@if (auth()->user()?->can('create', \App\Models\Carica::class) || $caricaInModifica)
{{-- Modal create/edit (pattern legacy: ?edit=<uuid> precompila e auto-apre) --}}
<div class="modal fade" id="caricaModal" tabindex="-1" aria-labelledby="caricaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST"
                  action="{{ $caricaInModifica ? route('organi-sociali.update', $caricaInModifica) : route('organi-sociali.store') }}">
                @csrf
                @if ($caricaInModifica)
                    @method('PUT')
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="caricaModalLabel">
                        {{ $caricaInModifica ? 'Modifica carica' : 'Nuova carica' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="carica_socio_id" class="form-label">Socio <span class="text-danger">*</span></label>
                            <select class="form-select @error('socio_id') is-invalid @enderror" id="carica_socio_id" name="socio_id" required>
                                <option value="">Seleziona socio...</option>
                                @foreach ($soci as $socio)
                                    <option value="{{ $socio->id }}"
                                            @selected(old('socio_id', $caricaInModifica?->socio_id) === $socio->id)>
                                        {{ $socio->cognome }} {{ $socio->nome }} (n. {{ $socio->numero_socio }})
                                    </option>
                                @endforeach
                            </select>
                            @error('socio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="ruolo" class="form-label">Ruolo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('ruolo') is-invalid @enderror" id="ruolo" name="ruolo"
                                   list="ruoliSuggeriti" maxlength="100" required
                                   value="{{ old('ruolo', $caricaInModifica?->ruolo) }}" placeholder="es. Presidente">
                            <datalist id="ruoliSuggeriti">
                                @foreach ($ruoli as $ruolo)
                                    <option value="{{ $ruolo }}"></option>
                                @endforeach
                            </datalist>
                            @error('ruolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="inizio" class="form-label">Inizio mandato <span class="text-danger">*</span></label>
                            <input type="date" class="form-control @error('inizio') is-invalid @enderror" id="inizio" name="inizio"
                                   value="{{ old('inizio', $caricaInModifica?->inizio?->format('Y-m-d')) }}" required>
                            @error('inizio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fine" class="form-label">Fine mandato</label>
                            <input type="date" class="form-control @error('fine') is-invalid @enderror" id="fine" name="fine"
                                   value="{{ old('fine', $caricaInModifica?->fine?->format('Y-m-d')) }}">
                            <div class="form-text">Lascia vuoto per un mandato senza termine fissato.</div>
                            @error('fine')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="carica_note" class="form-label">Note</label>
                        <textarea class="form-control @error('note') is-invalid @enderror" id="carica_note" name="note"
                                  rows="2">{{ old('note', $caricaInModifica?->note) }}</textarea>
                        @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>{{ $caricaInModifica ? 'Aggiorna' : 'Salva' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        @if ($caricaInModifica || $errors->any())
            const modalEl = document.getElementById('caricaModal');
            if (modalEl) { new bootstrap.Modal(modalEl).show(); }
        @endif
    });
</script>
@endpush
