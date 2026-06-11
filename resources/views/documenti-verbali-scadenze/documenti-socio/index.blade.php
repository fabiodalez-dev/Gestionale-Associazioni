@extends('layouts.app')

@section('title', 'Documenti Soci')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-person-lines-fill me-2"></i>Documenti Soci</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="{{ route('documenti.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-text me-1"></i>Documenti Associazione
        </a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadSocioModal">
            <i class="bi bi-upload me-1"></i>Carica Documento
        </button>
    </div>
</div>

@if (session('link_temporaneo'))
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <div class="mb-2">
            <i class="bi bi-link-45deg me-1"></i>
            Link di download temporaneo per <strong>{{ session('link_temporaneo_nome') }}</strong>
            (valido {{ \App\Http\Controllers\DocumentiVerbaliScadenze\DocumentoController::MINUTI_LINK_TEMPORANEO }} minuti):
        </div>
        <div class="input-group input-group-sm">
            <input type="text" class="form-control" id="linkTemporaneoInput" value="{{ session('link_temporaneo') }}" readonly>
            <button class="btn btn-outline-secondary" type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('linkTemporaneoInput').value)">
                <i class="bi bi-clipboard me-1"></i>Copia
            </button>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
    </div>
@endif

<form method="GET" action="{{ route('documenti-socio.index') }}" class="row g-2 mb-3">
    <div class="col-md-4">
        <select class="form-select" name="socio_id" onchange="this.form.submit()">
            <option value="all" @selected($socioId === 'all' || $socioId === '')>Tutti i soci</option>
            @foreach ($soci as $socio)
                <option value="{{ $socio->id }}" @selected($socioId === $socio->id)>
                    {{ $socio->cognome }} {{ $socio->nome }} ({{ $socio->numero_socio }})
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-5">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="search" value="{{ $search }}"
                   placeholder="Cerca per nome file o descrizione...">
        </div>
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-outline-primary">Filtra</button>
        <a href="{{ route('documenti-socio.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="card">
    <div class="card-header">{{ $documenti->total() }} {{ $documenti->total() === 1 ? 'documento trovato' : 'documenti trovati' }}</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Socio</th>
                    <th>Nome File</th>
                    <th>Scadenza documento</th>
                    <th>Caricato il</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documenti as $documento)
                    <tr>
                        <td>
                            @if ($documento->socio)
                                <span class="badge bg-primary">{{ $documento->socio->cognome }} {{ $documento->socio->nome }}</span>
                            @else
                                <span class="text-muted">N/D</span>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $documento->nome_file }}</strong>
                            @if ($documento->descrizione)
                                <br><small class="text-muted">{{ $documento->descrizione }}</small>
                            @endif
                        </td>
                        <td>
                            @if ($documento->data_scadenza)
                                {{ $documento->data_scadenza->format('d/m/Y') }}
                                @if ($documento->data_scadenza->isPast())
                                    <span class="badge bg-danger ms-1">Scaduto</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $documento->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            <a href="{{ route('documenti-socio.download', $documento) }}" class="btn btn-sm btn-outline-primary" title="Scarica">
                                <i class="bi bi-download"></i>
                            </a>
                            <form method="POST" action="{{ route('documenti-socio.link-temporaneo', $documento) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-info" title="Genera link temporaneo">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                            </form>
                            @can('delete', $documento)
                            <form method="POST" action="{{ route('documenti-socio.destroy', $documento) }}" class="d-inline"
                                  onsubmit="return confirm('Sei sicuro di voler eliminare questo documento?');">
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
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>Nessun documento trovato.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($documenti->hasPages())
        <div class="card-footer">
            {{ $documenti->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>

{{-- Modal upload documento socio --}}
<div class="modal fade" id="uploadSocioModal" tabindex="-1" aria-labelledby="uploadSocioModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('documenti-socio.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadSocioModalLabel">Carica Documento Socio</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="socio_id_upload" class="form-label">Socio <span class="text-danger">*</span></label>
                        <select class="form-select @error('socio_id') is-invalid @enderror" id="socio_id_upload" name="socio_id" required>
                            <option value="">-- Seleziona socio --</option>
                            @foreach ($soci as $socio)
                                <option value="{{ $socio->id }}" @selected(old('socio_id', $socioId) === $socio->id)>
                                    {{ $socio->cognome }} {{ $socio->nome }} ({{ $socio->numero_socio }})
                                </option>
                            @endforeach
                        </select>
                        @error('socio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="document_file_socio" class="form-label">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control @error('document_file') is-invalid @enderror"
                               id="document_file_socio" name="document_file" required
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                        <div class="form-text">Max 10MB. Formati: pdf, doc, docx, xls, xlsx, ppt, pptx, jpg, jpeg, png.</div>
                        @error('document_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="data_scadenza_doc" class="form-label">Scadenza documento</label>
                        <input type="date" class="form-control @error('data_scadenza') is-invalid @enderror"
                               id="data_scadenza_doc" name="data_scadenza" value="{{ old('data_scadenza') }}">
                        <div class="form-text">es. scadenza certificato medico (opzionale)</div>
                        @error('data_scadenza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="descrizione_socio" class="form-label">Descrizione</label>
                        <textarea class="form-control @error('descrizione') is-invalid @enderror"
                                  id="descrizione_socio" name="descrizione" rows="3">{{ old('descrizione') }}</textarea>
                        @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Carica</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        @if ($errors->any())
            new bootstrap.Modal(document.getElementById('uploadSocioModal')).show();
        @endif
    });
</script>
@endpush
