@extends('area-soci.layouts.app')

@section('title', 'Sondaggi - Area Riservata')

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-bar-chart me-2"></i>Sondaggi</h1>
    </div>

    @if ($sondaggi->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-bar-chart display-5 d-block mb-2"></i>
            Nessun sondaggio aperto al momento.
        </div>
    @else
        <p class="text-muted">La tua associazione vuole conoscere la tua opinione: vota i sondaggi aperti!</p>
        @foreach ($sondaggi as $voce)
            @include('comunicazione._sondaggio-voto', [
                'sondaggio' => $voce['sondaggio'],
                'risposta' => $voce['risposta'],
                'risultati' => $voce['risultati'],
                'actionVota' => route('area-soci.sondaggi.vota', $voce['sondaggio']),
            ])
        @endforeach
    @endif
@endsection
