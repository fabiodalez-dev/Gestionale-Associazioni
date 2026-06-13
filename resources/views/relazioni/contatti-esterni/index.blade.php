@extends('layouts.app')

@section('title', 'Contatti esterni - '.session('app_name', config('app.name')))

@php
    use App\Models\ContattoEsterno;
    $tipiLabel = [
        ContattoEsterno::TIPO_FORNITORE => 'Fornitore',
        ContattoEsterno::TIPO_ENTE => 'Ente',
        ContattoEsterno::TIPO_SPONSOR => 'Sponsor',
        ContattoEsterno::TIPO_STAMPA => 'Stampa',
        ContattoEsterno::TIPO_ALTRO => 'Altro',
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-person-rolodex me-2"></i>Contatti esterni</h1>
            <p class="text-muted mb-0">Mini-CRM di fornitori, enti, sponsor e stampa.</p>
        </div>
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#nuovoContatto"
                aria-expanded="{{ $errors->any() && !old('_edit') ? 'true' : 'false' }}" aria-controls="nuovoContatto">
            <i class="bi bi-plus-lg me-1"></i>Nuovo contatto
        </button>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- Form di creazione --}}
    <div class="collapse {{ $errors->any() && !old('_edit') ? 'show' : '' }}" id="nuovoContatto">
        <div class="card mb-3">
            <div class="card-body">
                <form method="POST" action="{{ route('contatti-esterni.store') }}">
                    @csrf
                    @include('relazioni.contatti-esterni._form', ['contatto' => null])
                    <div class="text-end mt-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea contatto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Ricerca + filtro tipo --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('contatti-esterni.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label small mb-1" for="q">Ricerca</label>
                    <input type="search" class="form-control form-control-sm" id="q" name="q" value="{{ $ricerca }}"
                           placeholder="Nome, referente o email">
                </div>
                <div class="col-8 col-md-4">
                    <label class="form-label small mb-1" for="tipo">Tipo</label>
                    <select class="form-select form-select-sm" id="tipo" name="tipo" onchange="this.form.submit()">
                        <option value="tutti" @selected($tipo === 'tutti')>Tutti i tipi</option>
                        @foreach ($tipiLabel as $valore => $label)
                            <option value="{{ $valore }}" @selected($tipo === $valore)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Cerca</button>
                    @if ($ricerca !== '' || $tipo !== 'tutti')
                        <a href="{{ route('contatti-esterni.index') }}" class="btn btn-sm btn-outline-secondary">Azzera</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    @if ($contatti->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-inbox me-1"></i>Nessun contatto con i filtri correnti.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Tipo</th>
                        <th>Referente</th>
                        <th>Contatti</th>
                        <th>Tag</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contatti as $contatto)
                        <tr>
                            <td class="fw-semibold">{{ $contatto->nome }}</td>
                            <td><span class="badge text-bg-light border">{{ $tipiLabel[$contatto->tipo] ?? $contatto->tipo }}</span></td>
                            <td class="small">{{ $contatto->referente ?: '—' }}</td>
                            <td class="small">
                                @if ($contatto->email)<div><i class="bi bi-envelope me-1"></i>{{ $contatto->email }}</div>@endif
                                @if ($contatto->telefono)<div><i class="bi bi-telephone me-1"></i>{{ $contatto->telefono }}</div>@endif
                                @if (!$contatto->email && !$contatto->telefono)<span class="text-muted">—</span>@endif
                            </td>
                            <td>
                                @foreach (($contatto->tag ?? []) as $t)
                                    <span class="badge text-bg-secondary">{{ $t }}</span>
                                @endforeach
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#editContatto-{{ $contatto->id }}" aria-expanded="false" title="Modifica">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="{{ route('contatti-esterni.destroy', $contatto) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questo contatto?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <tr class="collapse" id="editContatto-{{ $contatto->id }}">
                            <td colspan="6" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('contatti-esterni.update', $contatto) }}">
                                    @csrf
                                    @method('PUT')
                                    @include('relazioni.contatti-esterni._form', ['contatto' => $contatto])
                                    <div class="text-end mt-2">
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Salva modifiche</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $contatti->links() }}
    @endif
@endsection
