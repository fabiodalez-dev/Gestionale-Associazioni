@extends('layouts.app')

@section('title', 'Campi Personalizzati - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-journal-plus me-2"></i>Campi Personalizzati</h1>
        <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#presetModal">
            <i class="bi bi-stars me-1"></i>Campi predefiniti
        </button>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white">Nuovo campo</div>
        <div class="card-body">
            <form method="POST" action="{{ route('config-campi.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="nome_campo">Nome campo *</label>
                    <input type="text" class="form-control" id="nome_campo" name="nome_campo" required value="{{ old('nome_campo') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="tipo_campo">Tipo *</label>
                    <select class="form-select" id="tipo_campo" name="tipo_campo" required
                            onchange="document.getElementById('opzioniWrap').style.display = this.value === 'select' ? '' : 'none'">
                        @foreach ($tipiCampo as $tipo)
                            <option value="{{ $tipo }}" @selected(old('tipo_campo') === $tipo)>{{ $tipo }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="descrizione">Descrizione</label>
                    <input type="text" class="form-control" id="descrizione" name="descrizione" value="{{ old('descrizione') }}">
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="obbligatorio" name="obbligatorio" value="1" @checked(old('obbligatorio'))>
                        <label class="form-check-label" for="obbligatorio">Obbligatorio</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
                </div>
                <div class="col-12" id="opzioniWrap" style="display: {{ old('tipo_campo') === 'select' ? '' : 'none' }}">
                    <label class="form-label" for="opzioni">Opzioni (una per riga, per tipo select)</label>
                    <textarea class="form-control" id="opzioni" name="opzioni" rows="3">{{ old('opzioni') }}</textarea>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @if ($campi->isEmpty())
                <p class="text-muted mb-0">Nessun campo personalizzato definito.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Nome</th><th>Tipo</th><th>Obbligatorio</th><th>Descrizione</th><th>Valori</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                            @foreach ($campi as $campo)
                                <tr>
                                    <td class="fw-semibold">{{ $campo->nome_campo }}</td>
                                    <td><code>{{ $campo->tipo_campo }}</code></td>
                                    <td>{{ $campo->obbligatorio ? 'Sì' : 'No' }}</td>
                                    <td>{{ $campo->descrizione ?: '—' }}</td>
                                    <td>{{ $campo->valori_count }}</td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-{{ $campo->id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="{{ route('config-campi.destroy', $campo) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare il campo «{{ $campo->nome_campo }}»? Verranno persi tutti i dati associati.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="collapse" id="edit-{{ $campo->id }}">
                                    <td colspan="6" class="bg-light">
                                        <form method="POST" action="{{ route('config-campi.update', $campo) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-3">
                                                <input type="text" class="form-control form-control-sm" name="nome_campo" required value="{{ $campo->nome_campo }}">
                                            </div>
                                            <div class="col-md-2">
                                                <select class="form-select form-select-sm" name="tipo_campo" required>
                                                    @foreach ($tipiCampo as $tipo)
                                                        <option value="{{ $tipo }}" @selected($campo->tipo_campo === $tipo)>{{ $tipo }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control form-control-sm" name="descrizione" value="{{ $campo->descrizione }}" placeholder="Descrizione">
                                            </div>
                                            <div class="col-md-2">
                                                <textarea class="form-control form-control-sm" name="opzioni" rows="1" placeholder="Opzioni (select)">{{ $campo->opzioni }}</textarea>
                                            </div>
                                            <div class="col-md-1">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="obbligatorio" value="1" @checked($campo->obbligatorio)>
                                                    <label class="form-check-label small">Obblig.</label>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="submit" class="btn btn-sm btn-primary w-100">Salva</button>
                                            </div>
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

    {{-- Modal campi predefiniti (spec §5.3) --}}
    <div class="modal fade" id="presetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('config-campi.preset') }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Campi predefiniti</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        @foreach ($preset as $nome => $definizione)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="preset[]" value="{{ $nome }}" id="preset_{{ $loop->index }}">
                                <label class="form-check-label" for="preset_{{ $loop->index }}">
                                    {{ $nome }} <code class="small">{{ $definizione['tipo_campo'] }}</code>
                                </label>
                            </div>
                        @endforeach
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Aggiungi selezionati</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
