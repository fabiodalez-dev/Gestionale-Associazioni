@extends('layouts.app')

@section('title', 'Cestino Documenti')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-trash3 me-2"></i>Cestino Documenti</h1>
    <a href="{{ route('documenti.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Torna ai Documenti
    </a>
</div>

<div class="card">
    <div class="card-header">{{ $documenti->total() }} {{ $documenti->total() === 1 ? 'documento nel cestino' : 'documenti nel cestino' }}</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nome File</th>
                    <th>Categoria</th>
                    <th>Eliminato il</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documenti as $documento)
                    <tr>
                        <td>
                            <strong>{{ $documento->nome_file }}</strong>
                            @if ($documento->descrizione)
                                <br><small class="text-muted">{{ $documento->descrizione }}</small>
                            @endif
                        </td>
                        <td>{{ $documento->categoria ?: 'N/D' }}</td>
                        <td>{{ $documento->deleted_at?->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('documenti.ripristina', $documento->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Ripristina">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Ripristina
                                </button>
                            </form>
                            <form method="POST" action="{{ route('documenti.elimina-definitivamente', $documento->id) }}" class="d-inline"
                                  onsubmit="return confirm('Eliminare definitivamente questo documento? Il file sarà rimosso e l\'operazione non è reversibile.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina definitivamente">
                                    <i class="bi bi-x-octagon me-1"></i>Elimina definitivamente
                                </button>
                            </form>
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
    @if ($documenti->hasPages())
        <div class="card-footer">
            {{ $documenti->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>
@endsection
