@extends('layouts.app')

@section('title', 'Cessazioni')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-person-dash me-2"></i>Cessazioni</h1>
    @can('create', \App\Models\Cessazione::class)
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#cessazioneModal">
        <i class="bi bi-plus-lg me-1"></i>Registra cessazione
    </button>
    @endcan
</div>

<p class="text-muted">
    Registro formale delle cessazioni del rapporto associativo (dimissioni, decadenza, espulsione, decesso).
    La registrazione aggiorna lo stato del socio, annulla le tessere attive e traccia l'operazione
    nello storico del socio e nel libro soci.
</p>

<div class="card">
    <div class="card-header">{{ $cessazioni->total() }} {{ $cessazioni->total() === 1 ? 'cessazione registrata' : 'cessazioni registrate' }}</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Socio</th>
                    <th>Tipo</th>
                    <th>Data</th>
                    <th>Motivo</th>
                    <th>Delibera</th>
                    <th>Registrata da</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cessazioni as $cessazione)
                    <tr>
                        <td>
                            @if ($cessazione->socio)
                                <a href="{{ route('soci.show', $cessazione->socio) }}">{{ $cessazione->socio->cognome }} {{ $cessazione->socio->nome }}</a>
                                <small class="text-muted d-block">Socio n. {{ $cessazione->socio->numero_socio }}</small>
                            @else
                                <span class="text-muted fst-italic">Socio eliminato</span>
                            @endif
                        </td>
                        <td><span class="badge bg-{{ $cessazione->tipo === \App\Models\Cessazione::TIPO_DECESSO ? 'dark' : 'danger' }}">{{ ucfirst($cessazione->tipo) }}</span></td>
                        <td>{{ $cessazione->data?->format('d/m/Y') }}</td>
                        <td>{{ $cessazione->motivo ? \Illuminate\Support\Str::limit($cessazione->motivo, 80) : '—' }}</td>
                        <td>
                            @if (filled($cessazione->delibera_numero))
                                n. {{ $cessazione->delibera_numero }}
                                @if ($cessazione->delibera_data) <small class="text-muted">del {{ $cessazione->delibera_data->format('d/m/Y') }}</small> @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $cessazione->registrataDa?->nome_completo ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>Nessuna cessazione registrata.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($cessazioni->hasPages())
        <div class="card-footer">
            {{ $cessazioni->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>

@can('create', \App\Models\Cessazione::class)
{{-- Modal registrazione cessazione (azione formale sul socio, f.15) --}}
<div class="modal fade" id="cessazioneModal" tabindex="-1" aria-labelledby="cessazioneModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('cessazioni.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="cessazioneModalLabel">Registra cessazione</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Operazione formale: lo stato del socio sarà aggiornato e le sue tessere attive annullate.
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="socio_id" class="form-label">Socio <span class="text-danger">*</span></label>
                            <select class="form-select @error('socio_id') is-invalid @enderror" id="socio_id" name="socio_id" required>
                                <option value="">Seleziona socio...</option>
                                @foreach ($sociCessabili as $socio)
                                    <option value="{{ $socio->id }}" @selected(old('socio_id') === $socio->id)>
                                        {{ $socio->cognome }} {{ $socio->nome }} (n. {{ $socio->numero_socio }} — {{ $socio->stato }})
                                    </option>
                                @endforeach
                            </select>
                            @error('socio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="tipo" class="form-label">Tipo <span class="text-danger">*</span></label>
                            <select class="form-select @error('tipo') is-invalid @enderror" id="tipo" name="tipo" required>
                                @foreach ($tipi as $tipo)
                                    <option value="{{ $tipo }}" @selected(old('tipo') === $tipo)>{{ ucfirst($tipo) }}</option>
                                @endforeach
                            </select>
                            @error('tipo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="data" class="form-label">Data <span class="text-danger">*</span></label>
                            <input type="date" class="form-control @error('data') is-invalid @enderror" id="data" name="data"
                                   value="{{ old('data', now()->toDateString()) }}" required>
                            @error('data')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="motivo" class="form-label">Motivo</label>
                        <textarea class="form-control @error('motivo') is-invalid @enderror" id="motivo" name="motivo" rows="2"
                                  placeholder="Motivo della cessazione...">{{ old('motivo') }}</textarea>
                        @error('motivo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="delibera_numero" class="form-label">Delibera n.</label>
                            <input type="text" class="form-control @error('delibera_numero') is-invalid @enderror"
                                   id="delibera_numero" name="delibera_numero" maxlength="50"
                                   value="{{ old('delibera_numero') }}" placeholder="es. 12/2026">
                            @error('delibera_numero')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="delibera_data" class="form-label">Data delibera</label>
                            <input type="date" class="form-control @error('delibera_data') is-invalid @enderror"
                                   id="delibera_data" name="delibera_data" value="{{ old('delibera_data') }}">
                            @error('delibera_data')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('Confermi la registrazione della cessazione? Lo stato del socio sarà aggiornato e le tessere attive annullate.');">
                        <i class="bi bi-person-dash me-1"></i>Registra cessazione
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        @if ($errors->any())
            const modalEl = document.getElementById('cessazioneModal');
            if (modalEl) { new bootstrap.Modal(modalEl).show(); }
        @endif
    });
</script>
@endpush
