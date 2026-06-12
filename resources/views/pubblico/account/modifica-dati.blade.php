@extends('pubblico.layouts.app')

@section('title', 'Richiedi modifica dati — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Richiedi modifica dati</h1>

    @include('pubblico.account._nav')

    <p class="text-muted">
        Proponi le modifiche ai dati anagrafici della tua scheda socio
        (<strong>{{ $socio->nome_completo }}</strong>, n. {{ $socio->numero_socio }}):
        verranno applicate dopo l'approvazione dell'associazione.
        Compila solo i campi da cambiare.
    </p>

    @if ($richiestaPendente !== null)
        <div class="alert alert-info">
            <i class="bi bi-hourglass-split me-1"></i>
            C'è una richiesta inviata il <strong>{{ $richiestaPendente->created_at?->format('d/m/Y H:i') }}</strong>
            in attesa di approvazione. Potrai inviarne un'altra quando sarà stata gestita.
        </div>
    @else
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="bi bi-pencil-square me-1"></i>Nuova richiesta</div>
            <div class="card-body">
                <form method="POST" action="{{ route('pubblico.account.modifica-dati.store', $associazione->slug) }}">
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
