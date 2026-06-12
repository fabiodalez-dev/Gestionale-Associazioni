@extends('layouts.app')

@section('title', 'Cestino - '.session('app_name', config('app.name')))

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="bi bi-trash3 me-2"></i>Cestino</h1>
        <p class="text-muted mb-0">Elementi eliminati di soci, eventi, documenti e verbali. Lo svuotamento automatico avviene dopo 90 giorni.</p>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
    </div>
@endif

<form method="GET" action="{{ route('cestino.index') }}" class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
        <div>
            <label for="filtroTipo" class="form-label small text-muted mb-1">Tipo</label>
            <select name="tipo" id="filtroTipo" class="form-select form-select-sm">
                <option value="">Tutti i tipi</option>
                @foreach ($tipiVisibili as $tipo)
                    <option value="{{ $tipo }}" @selected($tipoFiltro === $tipo)>{{ $tipi[$tipo]['etichetta'] }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
        @if ($tipoFiltro !== '')
            <a href="{{ route('cestino.index') }}" class="btn btn-sm btn-outline-secondary">Azzera</a>
        @endif
    </div>
</form>

<div class="card">
    <div class="card-header">
        {{ $elementi->total() }} {{ $elementi->total() === 1 ? 'elemento nel cestino' : 'elementi nel cestino' }}
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Elemento</th>
                    <th>Eliminato il</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($elementi as $riga)
                    @php
                        /** @var \Illuminate\Database\Eloquent\Model $record */
                        $record = $riga['record'];
                        $config = $riga['config'];
                    @endphp
                    <tr>
                        <td>
                            <span class="badge bg-secondary"><i class="bi {{ $config['icona'] }} me-1"></i>{{ $config['etichetta'] }}</span>
                        </td>
                        <td>
                            <strong>{{ $riga['etichetta'] }}</strong>
                            @if ($riga['dettaglio'])
                                <br><small class="text-muted">{{ $riga['dettaglio'] }}</small>
                            @endif
                        </td>
                        <td>{{ $riga['eliminato_il']?->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            @can($config['ability_restore'], $record)
                                <form method="POST" action="{{ route('cestino.ripristina', ['tipo' => $riga['tipo'], 'id' => $record->getKey()]) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Ripristina">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Ripristina
                                    </button>
                                </form>
                            @endcan
                            @can($config['ability_force'], $record)
                                <form method="POST" action="{{ route('cestino.elimina-definitivamente', ['tipo' => $riga['tipo'], 'id' => $record->getKey()]) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare definitivamente questo elemento? L\'operazione non è reversibile.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina definitivamente">
                                        <i class="bi bi-x-octagon me-1"></i>Elimina definitivamente
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            <i class="bi bi-trash3 fs-3 d-block mb-2"></i>Il cestino è vuoto.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($elementi->hasPages())
        <div class="card-footer">
            {{ $elementi->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>
@endsection
