@extends('layouts.app')

@section('title', 'Cestino Soci - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-trash me-2"></i>Cestino Soci</h1>
        <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
    </div>

    @if ($soci->isEmpty())
        <div class="alert alert-light border text-center"><i class="bi bi-check-circle me-1"></i>Il cestino è vuoto.</div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Socio</th><th>Email</th><th>Numero</th><th>Eliminato il</th><th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($soci as $socio)
                        <tr>
                            <td class="fw-semibold">{{ $socio->cognome }} {{ $socio->nome }}</td>
                            <td>{{ $socio->email }}</td>
                            <td>{{ $socio->numero_socio }}</td>
                            <td>{{ $socio->deleted_at?->format('d/m/Y H:i') }}</td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('soci.restore', $socio->id) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Ripristina</button>
                                </form>
                                <form method="POST" action="{{ route('soci.force-destroy', $socio->id) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare DEFINITIVAMENTE questo socio? L\'operazione non è reversibile.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-octagon me-1"></i>Elimina definitivamente</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-center">{{ $soci->links() }}</div>
    @endif
@endsection
