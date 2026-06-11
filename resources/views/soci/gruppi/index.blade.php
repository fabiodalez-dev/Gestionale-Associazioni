@extends('layouts.app')

@section('title', 'Gruppi Dinamici - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-collection me-2"></i>Gruppi Dinamici</h1>
    </div>

    <p class="text-muted">
        Un gruppo dinamico è un filtro salvato: viene applicato live alla lista soci e non materializza l'elenco dei membri.
    </p>

    <div class="card mb-3">
        <div class="card-header bg-white">Nuovo gruppo</div>
        <div class="card-body">
            <form method="POST" action="{{ route('config-gruppi.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="nome_gruppo">Nome *</label>
                    <input type="text" class="form-control" id="nome_gruppo" name="nome_gruppo" maxlength="100" required value="{{ old('nome_gruppo') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="descrizione">Descrizione</label>
                    <input type="text" class="form-control" id="descrizione" name="descrizione" value="{{ old('descrizione') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filtri_stato">Filtro: stato</label>
                    <select class="form-select" id="filtri_stato" name="filtri[stato]">
                        <option value="">Qualsiasi</option>
                        @foreach ($stati as $stato)
                            <option value="{{ $stato }}" @selected(old('filtri.stato') === $stato)>{{ $stato }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filtri_citta">Filtro: città</label>
                    <input type="text" class="form-control" id="filtri_citta" name="filtri[citta]" value="{{ old('filtri.citta') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @if ($gruppi->isEmpty())
                <p class="text-muted mb-0">Nessun gruppo dinamico definito.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Nome</th><th>Descrizione</th><th>Filtri</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                            @foreach ($gruppi as $gruppo)
                                <tr>
                                    <td class="fw-semibold">{{ $gruppo->nome_gruppo }}</td>
                                    <td>{{ $gruppo->descrizione ?: '—' }}</td>
                                    <td><code class="small">{{ json_encode($gruppo->filtri_json, JSON_UNESCAPED_UNICODE) }}</code></td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('soci.index', ['gruppo_id' => $gruppo->id]) }}" class="btn btn-sm btn-outline-secondary" title="Applica in lista soci">
                                            <i class="bi bi-funnel"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-{{ $gruppo->id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="{{ route('config-gruppi.destroy', $gruppo) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare il gruppo «{{ $gruppo->nome_gruppo }}»?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="collapse" id="edit-{{ $gruppo->id }}">
                                    <td colspan="4" class="bg-light">
                                        <form method="POST" action="{{ route('config-gruppi.update', $gruppo) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-3">
                                                <input type="text" class="form-control form-control-sm" name="nome_gruppo" maxlength="100" required value="{{ $gruppo->nome_gruppo }}">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control form-control-sm" name="descrizione" value="{{ $gruppo->descrizione }}">
                                            </div>
                                            <div class="col-md-2">
                                                <select class="form-select form-select-sm" name="filtri[stato]">
                                                    <option value="">Qualsiasi stato</option>
                                                    @foreach ($stati as $stato)
                                                        <option value="{{ $stato }}" @selected(($gruppo->filtri_json['stato'] ?? null) === $stato)>{{ $stato }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control form-control-sm" name="filtri[citta]" placeholder="Città"
                                                       value="{{ $gruppo->filtri_json['citta'] ?? '' }}">
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
@endsection
