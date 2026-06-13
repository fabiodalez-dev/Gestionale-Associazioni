@extends('layouts.app')

@section('title', 'Mansioni - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-tools me-2"></i>Mansioni</h1>
            <p class="text-muted mb-0">Competenze e ruoli del volontariato assegnabili ai soci.</p>
        </div>
        <a href="{{ route('turni.index') }}" class="btn btn-outline-secondary"><i class="bi bi-person-raised-hand me-1"></i>Turni</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="POST" action="{{ route('mansioni.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="nome">Nome mansione</label>
                    <input type="text" class="form-control @error('nome') is-invalid @enderror" id="nome" name="nome"
                           value="{{ old('nome') }}" maxlength="100" required>
                    @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="descrizione">Descrizione</label>
                    <input type="text" class="form-control" id="descrizione" name="descrizione"
                           value="{{ old('descrizione') }}" maxlength="1000">
                </div>
                <div class="col-md-2 text-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
                </div>
            </form>
        </div>
    </div>

    @if ($mansioni->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-info-circle me-1"></i>Nessuna mansione ancora creata.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle bg-white">
                <thead>
                    <tr>
                        <th>Mansione</th>
                        <th>Descrizione</th>
                        <th class="text-center">Soci</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mansioni as $mansione)
                        <tr>
                            <td class="fw-semibold">{{ $mansione->nome }}</td>
                            <td class="text-muted">{{ $mansione->descrizione ?: '—' }}</td>
                            <td class="text-center"><span class="badge bg-secondary">{{ $mansione->soci_count }}</span></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse"
                                        data-bs-target="#edit-{{ $mansione->id }}" title="Modifica"><i class="bi bi-pencil"></i></button>
                                <form method="POST" action="{{ route('mansioni.destroy', $mansione) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questa mansione?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <tr class="collapse" id="edit-{{ $mansione->id }}">
                            <td colspan="4" class="bg-light">
                                <form method="POST" action="{{ route('mansioni.update', $mansione) }}" class="row g-2 align-items-end">
                                    @csrf @method('PUT')
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">Nome</label>
                                        <input type="text" class="form-control form-control-sm" name="nome" value="{{ $mansione->nome }}" maxlength="100" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Descrizione</label>
                                        <input type="text" class="form-control form-control-sm" name="descrizione" value="{{ $mansione->descrizione }}" maxlength="1000">
                                    </div>
                                    <div class="col-md-2 text-end">
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
@endsection
