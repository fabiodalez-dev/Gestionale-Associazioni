@extends('layouts.app')

@section('title', 'Tipi Socio - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-person-badge me-2"></i>Tipi di Socio</h1>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white">Nuovo tipo</div>
        <div class="card-body">
            <form method="POST" action="{{ route('tipi-socio.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="nome">Nome *</label>
                    <input type="text" class="form-control" id="nome" name="nome" required value="{{ old('nome') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="descrizione">Descrizione</label>
                    <input type="text" class="form-control" id="descrizione" name="descrizione" value="{{ old('descrizione') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="costo_tessera">Costo tessera (€) <span class="text-muted small">vuoto = default associazione</span></label>
                    <input type="number" step="0.01" min="0" class="form-control" id="costo_tessera" name="costo_tessera" value="{{ old('costo_tessera') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @if ($tipi->isEmpty())
                <p class="text-muted mb-0">Nessun tipo di socio definito.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Nome</th><th>Descrizione</th><th>Costo tessera</th><th>Soci</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                            @foreach ($tipi as $tipo)
                                <tr>
                                    <td class="fw-semibold">{{ $tipo->nome }}</td>
                                    <td>{{ $tipo->descrizione ?: '—' }}</td>
                                    <td>{{ $tipo->costo_tessera !== null ? '€ '.number_format((float) $tipo->costo_tessera, 2, ',', '.') : 'Default associazione' }}</td>
                                    <td>{{ $tipo->soci_count }}</td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-{{ $tipo->id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="{{ route('tipi-socio.destroy', $tipo) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare il tipo «{{ $tipo->nome }}»? I soci associati resteranno senza tipo.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="collapse" id="edit-{{ $tipo->id }}">
                                    <td colspan="5" class="bg-light">
                                        <form method="POST" action="{{ route('tipi-socio.update', $tipo) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-3">
                                                <input type="text" class="form-control form-control-sm" name="nome" required value="{{ $tipo->nome }}">
                                            </div>
                                            <div class="col-md-4">
                                                <input type="text" class="form-control form-control-sm" name="descrizione" value="{{ $tipo->descrizione }}">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="costo_tessera" value="{{ $tipo->costo_tessera }}">
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
