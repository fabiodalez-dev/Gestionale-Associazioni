@extends('layouts.app')

@section('title', 'Documenti')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-file-earmark-text me-2"></i>Documenti</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="{{ route('documenti-socio.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-person-lines-fill me-1"></i>Documenti Soci
        </a>
        @if (auth()->user()->isSuperAdmin() || auth()->user()->isAdminAssociazione())
        <a href="{{ route('documenti.cestino') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-trash3 me-1"></i>Cestino
        </a>
        @endif
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">
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

{{-- Filtri unificati (fix del difetto legacy: ricerca e categoria nella stessa query string) --}}
<form method="GET" action="{{ route('documenti.index') }}" class="row g-2 mb-3">
    <div class="col-md-5">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="search" value="{{ $search }}"
                   placeholder="Cerca per nome file o descrizione...">
        </div>
    </div>
    <div class="col-md-4">
        <select class="form-select" name="category" onchange="this.form.submit()">
            <option value="all" @selected($category === 'all' || $category === '')>Tutte le categorie</option>
            @foreach ($categorie as $cat)
                <option value="{{ $cat }}" @selected($category === $cat)>{{ $cat }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-outline-primary">Filtra</button>
        <a href="{{ route('documenti.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>{{ $documenti->total() }} {{ $documenti->total() === 1 ? 'documento trovato' : 'documenti trovati' }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nome File</th>
                    <th>Categoria</th>
                    <th>Caricato da</th>
                    <th>Data</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documenti as $documento)
                    <tr>
                        <td>
                            <strong>{{ $documento->nome_file }}</strong>
                            @if ($documento->descrizione)
                                <br><small class="text-muted">{{ $documento->descrizione }}</small>
                            @endif
                        </td>
                        <td>{{ $documento->categoria ?: 'N/D' }}</td>
                        <td>{{ $documento->caricatoDa?->nome_completo ?? 'N/D' }}</td>
                        <td>{{ $documento->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            <a href="{{ route('documenti.download', $documento) }}" class="btn btn-sm btn-outline-primary" title="Scarica">
                                <i class="bi bi-download"></i>
                            </a>
                            <form method="POST" action="{{ route('documenti.link-temporaneo', $documento) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-info" title="Genera link temporaneo">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                            </form>
                            @can('delete', $documento)
                            <form method="POST" action="{{ route('documenti.destroy', $documento) }}" class="d-inline"
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

{{-- Modal upload --}}
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('documenti.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadModalLabel">Carica Documento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="document_file" class="form-label">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control @error('document_file') is-invalid @enderror"
                               id="document_file" name="document_file" required
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                        <div class="form-text">Max 10MB. Formati: pdf, doc, docx, xls, xlsx, ppt, pptx, jpg, jpeg, png.</div>
                        <div id="filePreview" class="small mt-1"></div>
                        @error('document_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="categoria" class="form-label">Categoria</label>
                        <input type="text" class="form-control @error('categoria') is-invalid @enderror"
                               id="categoria" name="categoria" value="{{ old('categoria') }}"
                               placeholder="es. Statuti, Verbali, Regolamenti" list="categorieList">
                        <datalist id="categorieList">
                            @foreach ($categorie as $cat)
                                <option value="{{ $cat }}"></option>
                            @endforeach
                        </datalist>
                        @error('categoria')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control @error('descrizione') is-invalid @enderror"
                                  id="descrizione" name="descrizione" rows="3">{{ old('descrizione') }}</textarea>
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
        // Preview del nome file selezionato (comportamento legacy)
        const input = document.getElementById('document_file');
        const preview = document.getElementById('filePreview');
        if (input && preview) {
            input.addEventListener('change', function () {
                preview.innerHTML = this.files.length
                    ? '<i class="bi bi-file-earmark me-1"></i>' + this.files[0].name
                    : '';
            });
        }

        // Riapri il modal se l'upload ha errori di validazione
        @if ($errors->any())
            new bootstrap.Modal(document.getElementById('uploadModal')).show();
        @endif
    });
</script>
@endpush
