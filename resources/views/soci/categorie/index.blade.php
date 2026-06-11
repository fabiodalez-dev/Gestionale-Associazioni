@extends('layouts.app')

@section('title', 'Categorie Socio - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-bookmark-star me-2"></i>Categorie di Socio</h1>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white">Nuova categoria</div>
        <div class="card-body">
            <form method="POST" action="{{ route('categorie-socio.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="nome">Nome *</label>
                    <input type="text" class="form-control" id="nome" name="nome" required value="{{ old('nome') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="descrizione">Descrizione</label>
                    <input type="text" class="form-control" id="descrizione" name="descrizione" value="{{ old('descrizione') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="testo_tessera">Testo tessera <span class="text-muted small">(stampato sul PDF)</span></label>
                    <input type="text" class="form-control" id="testo_tessera" name="testo_tessera" value="{{ old('testo_tessera') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @if ($categorie->isEmpty())
                <p class="text-muted mb-0">Nessuna categoria definita.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Nome</th><th>Descrizione</th><th>Testo tessera</th><th>Soci</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                            @foreach ($categorie as $categoria)
                                <tr>
                                    <td class="fw-semibold">{{ $categoria->nome }}</td>
                                    <td>{{ $categoria->descrizione ?: '—' }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($categoria->testo_tessera, 60) ?: '—' }}</td>
                                    <td>{{ $categoria->soci_count }}</td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-{{ $categoria->id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="{{ route('categorie-socio.destroy', $categoria) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare la categoria «{{ $categoria->nome }}»? I soci associati resteranno senza categoria.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="collapse" id="edit-{{ $categoria->id }}">
                                    <td colspan="5" class="bg-light">
                                        <form method="POST" action="{{ route('categorie-socio.update', $categoria) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-3">
                                                <input type="text" class="form-control form-control-sm" name="nome" required value="{{ $categoria->nome }}">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control form-control-sm" name="descrizione" value="{{ $categoria->descrizione }}">
                                            </div>
                                            <div class="col-md-4">
                                                <textarea class="form-control form-control-sm" name="testo_tessera" rows="2">{{ $categoria->testo_tessera }}</textarea>
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
