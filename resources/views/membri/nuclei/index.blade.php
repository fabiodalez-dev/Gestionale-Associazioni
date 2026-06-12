@extends('layouts.app')

@section('title', 'Soci per nucleo - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-house-heart me-2"></i>Soci per nucleo familiare</h1>
            <p class="text-muted mb-0">
                {{ $nuclei->total() }} {{ $nuclei->total() === 1 ? 'nucleo' : 'nuclei' }}
                &middot; {{ $sociSenzaNucleo }} soci senza nucleo
            </p>
        </div>
        <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Lista soci
        </a>
    </div>

    <form method="GET" action="{{ route('nuclei.index') }}" class="row g-2 mb-3" style="max-width: 480px;">
        <div class="col-8">
            <input type="text" class="form-control form-control-sm" name="search" value="{{ $search }}"
                   placeholder="Cerca nucleo per nome...">
        </div>
        <div class="col-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
            @if ($search !== '')
                <a href="{{ route('nuclei.index') }}" class="btn btn-sm btn-outline-secondary">Azzera</a>
            @endif
        </div>
    </form>

    @if ($nuclei->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-house me-1"></i>Nessun nucleo familiare.
            Puoi crearne uno dalla scheda di un socio (tab Anagrafica → Nucleo familiare).
        </div>
    @else
        <div class="row g-3">
            @foreach ($nuclei as $nucleo)
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <span class="fw-semibold"><i class="bi bi-house-heart me-1"></i>{{ $nucleo->nome }}</span>
                            <span class="badge bg-secondary">{{ $nucleo->membri->count() }} {{ $nucleo->membri->count() === 1 ? 'membro' : 'membri' }}</span>
                        </div>
                        <div class="card-body py-2">
                            @if ($nucleo->membri->isEmpty())
                                <p class="text-muted small mb-0">Nessun membro.</p>
                            @else
                                <ul class="list-group list-group-flush">
                                    @foreach ($nucleo->membri as $membro)
                                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                            <span>
                                                <a href="{{ route('soci.show', $membro) }}" class="text-decoration-none">
                                                    {{ $membro->cognome }} {{ $membro->nome }}
                                                </a>
                                                <span class="text-muted small">— n. {{ $membro->numero_socio }}</span>
                                                @if ($membro->isMinorenne())
                                                    <span class="badge bg-info text-dark">Minorenne</span>
                                                @endif
                                            </span>
                                            @if ($nucleo->capofamiglia_id === $membro->id)
                                                <span class="badge bg-primary" title="Capofamiglia"><i class="bi bi-star-fill me-1"></i>Capofamiglia</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        @can('delete', $nucleo)
                            <div class="card-footer bg-white text-end">
                                <form method="POST" action="{{ route('nuclei.destroy', $nucleo) }}" class="d-inline"
                                      onsubmit="return confirm('Sciogliere il nucleo «{{ $nucleo->nome }}»? I soci non verranno modificati.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i>Sciogli nucleo
                                    </button>
                                </form>
                            </div>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $nuclei->links() }}
        </div>
    @endif
@endsection
