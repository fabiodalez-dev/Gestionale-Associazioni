@extends('layouts.app')

@section('title', 'Turni - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-person-raised-hand me-2"></i>Turni di volontariato</h1>
            <p class="text-muted mb-0">Pianifica i turni e assegna i volontari.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('volontari.index') }}" class="btn btn-outline-secondary"><i class="bi bi-people me-1"></i>Volontari</a>
            <a href="{{ route('registro-ore.index') }}" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i>Registro ore</a>
            <a href="{{ route('turni.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuovo turno</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('turni.index') }}" class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="filtro_stato">Stato</label>
                    <select class="form-select form-select-sm" id="filtro_stato" name="stato" onchange="this.form.submit()">
                        <option value="all" @selected($stato === 'all')>Tutti</option>
                        <option value="{{ \App\Models\Turno::STATO_APERTO }}" @selected($stato === \App\Models\Turno::STATO_APERTO)>Aperti</option>
                        <option value="{{ \App\Models\Turno::STATO_CHIUSO }}" @selected($stato === \App\Models\Turno::STATO_CHIUSO)>Chiusi</option>
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small mb-1" for="filtro_evento">Per evento</label>
                    <select class="form-select form-select-sm" id="filtro_evento" name="evento" onchange="this.form.submit()">
                        <option value="">Tutti gli eventi</option>
                        @foreach ($eventi as $e)
                            <option value="{{ $e->id }}" @selected($eventoId === $e->id)>{{ $e->titolo }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>
    </div>

    @if ($turni->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-info-circle me-1"></i>Nessun turno con i filtri correnti.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle bg-white">
                <thead>
                    <tr>
                        <th>Turno</th>
                        <th>Data e orario</th>
                        <th>Mansione</th>
                        <th>Evento</th>
                        <th class="text-center">Coperti</th>
                        <th class="text-center">Stato</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($turni as $turno)
                        <tr>
                            <td class="fw-semibold">
                                <a href="{{ route('turni.show', $turno) }}" class="text-decoration-none">{{ $turno->titolo }}</a>
                            </td>
                            <td>{{ $turno->data->format('d/m/Y') }} · {{ substr((string) $turno->ora_inizio, 0, 5) }}–{{ substr((string) $turno->ora_fine, 0, 5) }}</td>
                            <td>{{ $turno->mansione?->nome ?? '—' }}</td>
                            <td>{{ $turno->evento?->titolo ?? '—' }}</td>
                            <td class="text-center">
                                <span class="badge bg-success">{{ $turno->confermati_count }}</span>
                                @if ($turno->proposti_count > 0)<span class="badge bg-warning text-dark">+{{ $turno->proposti_count }}</span>@endif
                                <span class="text-muted small">/ {{ $turno->posti }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge {{ $turno->isAperto() ? 'bg-primary' : 'bg-secondary' }}">{{ ucfirst($turno->stato) }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('turni.show', $turno) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                <a href="{{ route('turni.edit', $turno) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $turni->links() }}
    @endif
@endsection
