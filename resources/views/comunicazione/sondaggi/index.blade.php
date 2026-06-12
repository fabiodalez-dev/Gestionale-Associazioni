@extends('layouts.app')

@section('title', 'Sondaggi')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-bar-chart me-2"></i>Sondaggi</h1>
    @can('create', \App\Models\Sondaggio::class)
        <a href="{{ route('sondaggi.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Nuovo sondaggio
        </a>
    @endcan
</div>

<p class="text-muted small">
    I sondaggi aperti sono votabili dall'area soci e dall'area riservata del mini-sito
    (un voto per persona); quelli "solo soci" sono esclusi per gli account non collegati a un socio.
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Domanda</th>
                    <th>Stato</th>
                    <th>Apertura</th>
                    <th>Visibilità</th>
                    <th class="text-center">Voti</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sondaggi as $sondaggio)
                    <tr>
                        <td>
                            <a href="{{ route('sondaggi.show', $sondaggio) }}" class="fw-semibold text-decoration-none">
                                {{ \Illuminate\Support\Str::limit($sondaggio->domanda, 80) }}
                            </a>
                            <div class="text-muted small">{{ count((array) $sondaggio->opzioni) }} opzioni</div>
                        </td>
                        <td>
                            @if ($sondaggio->isAperto())
                                <span class="badge bg-success">Aperto</span>
                            @elseif ($sondaggio->aperto_dal === null)
                                <span class="badge bg-secondary">Bozza</span>
                            @elseif ($sondaggio->aperto_dal->isFuture())
                                <span class="badge bg-info text-dark">Programmato</span>
                            @else
                                <span class="badge bg-dark">Chiuso</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($sondaggio->aperto_dal)
                                dal {{ $sondaggio->aperto_dal->format('d/m/Y H:i') }}
                                @if ($sondaggio->aperto_al)
                                    al {{ $sondaggio->aperto_al->format('d/m/Y H:i') }}
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($sondaggio->solo_soci)
                                <span class="badge bg-primary">Solo soci</span>
                            @else
                                <span class="badge bg-light text-dark border">Tutti</span>
                            @endif
                        </td>
                        <td class="text-center"><span class="badge bg-secondary">{{ $sondaggio->risposte_count }}</span></td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('sondaggi.show', $sondaggio) }}" class="btn btn-sm btn-outline-primary" title="Risultati">
                                <i class="bi bi-bar-chart"></i>
                            </a>
                            @can('update', $sondaggio)
                                <a href="{{ route('sondaggi.edit', $sondaggio) }}" class="btn btn-sm btn-outline-warning" title="Modifica">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            @endcan
                            @can('delete', $sondaggio)
                                <form method="POST" action="{{ route('sondaggi.destroy', $sondaggio) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare il sondaggio e tutte le risposte?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-bar-chart me-1"></i>Nessun sondaggio. Crea il primo!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-center mt-3">
    {{ $sondaggi->links() }}
</div>
@endsection
