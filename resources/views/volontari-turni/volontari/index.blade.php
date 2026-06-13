@extends('layouts.app')

@section('title', 'Volontari - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-people me-2"></i>Volontari</h1>
            <p class="text-muted mb-0">Soci disponibili al volontariato, filtrabili per mansione.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('mansioni.index') }}" class="btn btn-outline-secondary"><i class="bi bi-tools me-1"></i>Mansioni</a>
            <a href="{{ route('turni.index') }}" class="btn btn-outline-secondary"><i class="bi bi-person-raised-hand me-1"></i>Turni</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('volontari.index') }}" class="row g-2 align-items-end">
                <div class="col-6 col-md-4">
                    <label class="form-label small mb-1" for="filtro_mansione">Mansione</label>
                    <select class="form-select form-select-sm" id="filtro_mansione" name="mansione" onchange="this.form.submit()">
                        <option value="">Tutte le mansioni</option>
                        @foreach ($mansioni as $m)
                            <option value="{{ $m->id }}" @selected($mansioneId === $m->id)>{{ $m->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <div class="form-check mt-4">
                        <input type="hidden" name="attivi" value="0">
                        <input type="checkbox" class="form-check-input" id="filtro_attivi" name="attivi" value="1"
                               @checked($soloAttivi) onchange="this.form.submit()">
                        <label class="form-check-label" for="filtro_attivi">Solo volontari attivi</label>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($soci->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-info-circle me-1"></i>Nessun volontario con i filtri correnti.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle bg-white">
                <thead>
                    <tr>
                        <th>Socio</th>
                        <th>N. socio</th>
                        <th>Mansioni</th>
                        <th class="text-center">Attivo</th>
                        <th class="text-end">Scheda</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($soci as $socio)
                        <tr>
                            <td class="fw-semibold">{{ $socio->nome_completo }}</td>
                            <td class="text-muted">{{ $socio->numero_socio ?: '—' }}</td>
                            <td>
                                @forelse ($socio->mansioni as $m)
                                    <span class="badge bg-secondary">{{ $m->nome }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td class="text-center">
                                @if ($socio->volontario_attivo)
                                    <span class="badge bg-success">Sì</span>
                                @else
                                    <span class="badge bg-light text-dark border">No</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('soci.show', [$socio, 'tab' => 'volontariato']) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $soci->links() }}
    @endif
@endsection
