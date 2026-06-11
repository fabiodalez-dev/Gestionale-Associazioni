@extends('layouts.app')

@section('title', 'Template Tessere')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-file-text me-2"></i>Template Tessere</h1>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('tessere.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Tessere
        </a>
        <button type="button" class="btn btn-primary" id="btnNuovoTemplate">
            <i class="bi bi-plus-lg me-1"></i>Nuovo Template
        </button>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <p class="mb-1">Il testo del template viene stampato nel PDF della tessera. Placeholder disponibili:</p>
        <code class="small">{{ '{NOME} {COGNOME} {NOME_COMPLETO} {NUMERO_SOCIO} {CODICE_FISCALE} {INDIRIZZO} {CITTA} {PROVINCIA} {CAP} {TIPO_SOCIO} {CATEGORIA_SOCIO} {NUMERO_TESSERA} {ANNO_VALIDITA} {DATA_EMISSIONE} {DATA_SCADENZA} {ASSOCIAZIONE_NOME} {ASSOCIAZIONE_CODICE_FISCALE} {ASSOCIAZIONE_PARTITA_IVA} {ASSOCIAZIONE_INDIRIZZO} {ASSOCIAZIONE_CITTA} {ASSOCIAZIONE_PROVINCIA} {ASSOCIAZIONE_CAP} {ASSOCIAZIONE_EMAIL} {ASSOCIAZIONE_TELEFONO}' }}</code>
        <p class="small text-muted mt-2 mb-0">Il template specifico per tipo socio ha precedenza su quello generico; in assenza di entrambi si usa il testo tessera della categoria del socio.</p>
    </div>
</div>

<div class="card">
    <div class="card-header">Template configurati</div>
    <div class="card-body">
        @if ($templates->isEmpty())
            <p class="text-muted text-center py-4 mb-0">Nessun template configurato. Verrà usato il testo tessera della categoria socio.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Titolo</th>
                            <th>Tipo socio</th>
                            <th>Attivo</th>
                            <th>Anteprima</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($templates as $template)
                            <tr>
                                <td>{{ $template->titolo }}</td>
                                <td>
                                    @if ($template->tipoSocio)
                                        <span class="badge bg-primary">{{ $template->tipoSocio->nome }}</span>
                                    @else
                                        <span class="badge bg-secondary">Generico</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($template->attivo)
                                        <span class="badge bg-success">Sì</span>
                                    @else
                                        <span class="badge bg-secondary">No</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ \Illuminate\Support\Str::limit($template->contenuto, 80) }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-template"
                                            data-titolo="{{ $template->titolo }}"
                                            data-tipo-socio-id="{{ $template->tipo_socio_id }}"
                                            data-contenuto="{{ $template->contenuto }}"
                                            data-attivo="{{ $template->attivo ? '1' : '0' }}"
                                            data-update-url="{{ route('tessere.templates.update', $template) }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="{{ route('tessere.templates.destroy', $template) }}" class="d-inline"
                                          onsubmit="return confirm('Eliminare questo template?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

{{-- Modal crea/modifica template --}}
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" action="{{ route('tessere.templates.store') }}" id="templateForm">
            @csrf
            <input type="hidden" name="_method" value="POST" id="templateFormMethod">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle">Nuovo Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campoTitolo">Titolo</label>
                        <input type="text" class="form-control" id="campoTitolo" name="titolo" maxlength="255" value="Template Tessera">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campoTipoSocio">Tipo socio</label>
                        <select class="form-select" id="campoTipoSocio" name="tipo_socio_id">
                            <option value="">Generico (tutti i tipi)</option>
                            @foreach ($tipiSocio as $tipo)
                                <option value="{{ $tipo->id }}">{{ $tipo->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="campoContenuto">Contenuto <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="campoContenuto" name="contenuto" rows="8" required></textarea>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="campoAttivo" name="attivo" value="1" checked>
                    <label class="form-check-label" for="campoAttivo">Attivo</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">Salva</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function() {
    'use strict';

    var storeUrl = @json(route('tessere.templates.store'));
    var modalEl = document.getElementById('templateModal');
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var form = document.getElementById('templateForm');
    var methodField = document.getElementById('templateFormMethod');
    var title = document.getElementById('templateModalTitle');

    document.getElementById('btnNuovoTemplate').addEventListener('click', function() {
        form.action = storeUrl;
        methodField.value = 'POST';
        title.textContent = 'Nuovo Template';
        form.reset();
        if (modal) modal.show();
    });

    document.querySelectorAll('.btn-edit-template').forEach(function(btn) {
        btn.addEventListener('click', function() {
            form.action = btn.dataset.updateUrl;
            methodField.value = 'PUT';
            title.textContent = 'Modifica Template';
            document.getElementById('campoTitolo').value = btn.dataset.titolo || '';
            document.getElementById('campoTipoSocio').value = btn.dataset.tipoSocioId || '';
            document.getElementById('campoContenuto').value = btn.dataset.contenuto || '';
            document.getElementById('campoAttivo').checked = btn.dataset.attivo === '1';
            if (modal) modal.show();
        });
    });
})();
</script>
@endpush
