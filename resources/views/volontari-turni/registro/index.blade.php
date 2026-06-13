@extends('layouts.app')

@section('title', 'Registro ore volontariato - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-clock-history me-2"></i>Registro ore volontariato</h1>
            <p class="text-muted mb-0">Ore registrate a fine turno e inserimenti manuali.</p>
        </div>
        <a href="{{ route('turni.index') }}" class="btn btn-outline-secondary"><i class="bi bi-person-raised-hand me-1"></i>Turni</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle me-1"></i>Ore manuali (fuori turno)</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('registro-ore.manuali') }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="m_socio">Socio *</label>
                            <select class="form-select form-select-sm @error('socio_id') is-invalid @enderror" id="m_socio" name="socio_id" required>
                                <option value="">— Seleziona —</option>
                                @foreach ($sociVolontari as $s)
                                    <option value="{{ $s->id }}" @selected(old('socio_id') === $s->id)>{{ $s->nome_completo }}</option>
                                @endforeach
                            </select>
                            @error('socio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-2">
                                <label class="form-label small mb-1" for="m_ore">Ore *</label>
                                <input type="number" step="0.25" min="0.25" max="24" class="form-control form-control-sm @error('ore') is-invalid @enderror"
                                       id="m_ore" name="ore" value="{{ old('ore') }}" required>
                                @error('ore')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6 mb-2">
                                <label class="form-label small mb-1" for="m_data">Data *</label>
                                <input type="date" class="form-control form-control-sm @error('data') is-invalid @enderror"
                                       id="m_data" name="data" value="{{ old('data', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                                @error('data')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="m_desc">Descrizione</label>
                            <input type="text" class="form-control form-control-sm" id="m_desc" name="descrizione" value="{{ old('descrizione') }}" maxlength="500">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-save me-1"></i>Registra ore</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i>Ore registrate {{ $anno }}</span>
                    <form method="GET" action="{{ route('registro-ore.index') }}" class="d-flex align-items-center gap-2">
                        <input type="number" class="form-control form-control-sm" style="width:6rem" name="anno" value="{{ $anno }}"
                               min="2000" max="{{ now()->year + 1 }}" onchange="this.form.submit()">
                        <span class="badge bg-success">Totale: {{ rtrim(rtrim(number_format($totale, 2, ',', '.'), '0'), ',') }} h</span>
                    </form>
                </div>
                <div class="card-body p-0">
                    @if ($voci->isEmpty())
                        <p class="text-muted text-center my-4">Nessuna ora registrata per il {{ $anno }}.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr><th>Data</th><th>Socio</th><th>Attività</th><th class="text-end">Ore</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($voci as $v)
                                        <tr>
                                            <td>{{ $v->turno?->data?->format('d/m/Y') }}</td>
                                            <td>{{ $v->socio?->nome_completo }}</td>
                                            <td>{{ $v->turno?->titolo }}</td>
                                            <td class="text-end">{{ $v->ore_effettive }}</td>
                                            <td class="text-end">
                                                <form method="POST" action="{{ route('registro-ore.destroy', $v) }}" onsubmit="return confirm('Rimuovere questa voce?');">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger" title="Rimuovi"><i class="bi bi-trash"></i></button>
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
        </div>
    </div>
@endsection
