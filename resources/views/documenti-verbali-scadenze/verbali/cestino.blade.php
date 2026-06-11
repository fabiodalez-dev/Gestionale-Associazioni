@extends('layouts.app')

@section('title', 'Cestino Verbali')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-trash3 me-2"></i>Cestino Verbali</h1>
    <a href="{{ route('verbali.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Torna ai Verbali
    </a>
</div>

<div class="card">
    <div class="card-header">{{ $verbali->total() }} {{ $verbali->total() === 1 ? 'verbale nel cestino' : 'verbali nel cestino' }}</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Titolo</th>
                    <th>Data Riunione</th>
                    <th>Tipo</th>
                    <th>Eliminato il</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($verbali as $verbale)
                    <tr>
                        <td><strong>{{ $verbale->titolo }}</strong></td>
                        <td>{{ $verbale->data_riunione->format('d/m/Y') }}</td>
                        <td><span class="badge bg-info text-dark">{{ $verbale->tipo_riunione }}</span></td>
                        <td>{{ $verbale->deleted_at?->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('verbali.ripristina', $verbale->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Ripristina
                                </button>
                            </form>
                            <form method="POST" action="{{ route('verbali.elimina-definitivamente', $verbale->id) }}" class="d-inline"
                                  onsubmit="return confirm('Eliminare definitivamente questo verbale? L\'operazione non è reversibile.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-octagon me-1"></i>Elimina definitivamente
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-trash3 fs-3 d-block mb-2"></i>Il cestino è vuoto.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($verbali->hasPages())
        <div class="card-footer">
            {{ $verbali->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>
@endsection
