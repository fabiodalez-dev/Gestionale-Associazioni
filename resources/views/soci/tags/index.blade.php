@extends('layouts.app')

@section('title', 'Gestione Tag - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-tags me-2"></i>Gestione Tag</h1>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white">Nuovo tag</div>
        <div class="card-body">
            <form method="POST" action="{{ route('config-tags.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="nome_tag">Nome *</label>
                    <input type="text" class="form-control" id="nome_tag" name="nome_tag" maxlength="50" required value="{{ old('nome_tag') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="colore">Colore</label>
                    <input type="color" class="form-control form-control-color w-100" id="colore" name="colore" value="{{ old('colore', '#888888') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @if ($tags->isEmpty())
                <p class="text-muted mb-0">Nessun tag definito.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Tag</th><th>Colore</th><th>Soci</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                            @foreach ($tags as $tag)
                                <tr>
                                    <td><span class="badge" style="background-color: {{ $tag->colore }}">{{ $tag->nome_tag }}</span></td>
                                    <td><code>{{ $tag->colore }}</code></td>
                                    <td>{{ $tag->soci_count }}</td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-{{ $tag->id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="{{ route('config-tags.destroy', $tag) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare il tag «{{ $tag->nome_tag }}»? Verrà rimosso da tutti i soci.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="collapse" id="edit-{{ $tag->id }}">
                                    <td colspan="4" class="bg-light">
                                        <form method="POST" action="{{ route('config-tags.update', $tag) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-4">
                                                <input type="text" class="form-control form-control-sm" name="nome_tag" maxlength="50" required value="{{ $tag->nome_tag }}">
                                            </div>
                                            <div class="col-md-2">
                                                <input type="color" class="form-control form-control-color w-100" name="colore" value="{{ $tag->colore }}">
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
