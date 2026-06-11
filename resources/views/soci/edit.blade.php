@extends('layouts.app')

@section('title', 'Modifica Socio - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-pencil me-2"></i>Modifica Socio — {{ $socio->nome_completo }}</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('soci.show', $socio) }}" class="btn btn-outline-secondary"><i class="bi bi-eye me-1"></i>Dettaglio</a>
            <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Lista</a>
        </div>
    </div>

    {{-- Azioni rapide pre-iscrizione in modifica (spec §2.6) --}}
    @if (in_array($socio->stato, [\App\Models\Socio::STATO_IN_ATTESA, \App\Models\Socio::STATO_IN_ATTESA_DI_PAGAMENTO], true))
        <div class="alert alert-warning d-flex gap-2 align-items-center flex-wrap">
            <span><i class="bi bi-hourglass-split me-1"></i>Socio in stato <strong>{{ $socio->stato }}</strong>.</span>
            @if ($socio->stato === \App\Models\Socio::STATO_IN_ATTESA)
                <form method="POST" action="{{ route('soci.approva', $socio) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">Approva pre-iscrizione</button>
                </form>
            @endif
            <form method="POST" action="{{ route('soci.rifiuta', $socio) }}" class="d-inline"
                  onsubmit="return confirm('Rifiutare ed eliminare definitivamente questa pre-iscrizione?');">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-danger">Rifiuta</button>
            </form>
        </div>
    @endif

    <form method="POST" action="{{ route('soci.update', $socio) }}">
        @csrf
        @method('PUT')
        @include('soci.partials.form')
        <div class="mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva Modifiche</button>
        </div>
    </form>
@endsection
