@extends('area-soci.layouts.app')

@section('title', 'Richiedi modifica dati - '.$socio->nome_completo)

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Richiedi modifica dati</h1>
        <a href="{{ route('area-soci.profilo') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Torna al profilo
        </a>
    </div>

    <p class="text-muted">
        Proponi qui le modifiche ai tuoi dati anagrafici: verranno applicate dopo
        l'approvazione dell'associazione. Compila solo i campi da cambiare.
    </p>

    @if ($richiestaPendente !== null)
        <div class="alert alert-info">
            <i class="bi bi-hourglass-split me-1"></i>
            Hai una richiesta inviata il <strong>{{ $richiestaPendente->created_at?->format('d/m/Y H:i') }}</strong>
            in attesa di approvazione. Potrai inviarne un'altra quando sarà stata gestita.
        </div>
    @else
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-pencil-square me-2"></i>Nuova richiesta</div>
            <div class="card-body">
                <form method="POST" action="{{ route('area-soci.modifica-dati.store') }}">
                    @csrf
                    @include('membri.partials.form-modifica-dati', ['socio' => $socio, 'service' => $service])
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Invia richiesta
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @include('membri.partials.storico-richieste-modifica', ['richieste' => $richieste, 'service' => $service])
@endsection
