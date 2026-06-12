@extends('pubblico.layouts.app')

@section('title', 'Sondaggi — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Sondaggi</h1>

    @include('pubblico.account._nav')

    @if ($sondaggi->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-bar-chart display-5 d-block mb-2"></i>
            Nessun sondaggio aperto al momento.
        </div>
    @else
        <div style="max-width: 720px;">
            <p class="text-muted">{{ $associazione->nome }} vuole conoscere la tua opinione: vota i sondaggi aperti!</p>
            @foreach ($sondaggi as $voce)
                @include('comunicazione._sondaggio-voto', [
                    'sondaggio' => $voce['sondaggio'],
                    'risposta' => $voce['risposta'],
                    'risultati' => $voce['risultati'],
                    'actionVota' => route('pubblico.account.sondaggi.vota', [$associazione->slug, $voce['sondaggio']]),
                ])
            @endforeach
        </div>
    @endif
@endsection
