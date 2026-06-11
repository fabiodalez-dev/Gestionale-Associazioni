@extends('pubblico.layouts.app')

@section('title', 'Eventi — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Eventi in programma</h1>

    @if ($eventi->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x display-5 d-block mb-2"></i>
            Nessun evento in programma al momento. Torna a trovarci presto!
        </div>
    @else
        <div class="row g-4">
            @foreach ($eventi as $evento)
                <div class="col-12 col-md-6 col-lg-4">
                    @include('pubblico.eventi._card', ['evento' => $evento, 'associazione' => $associazione])
                </div>
            @endforeach
        </div>
    @endif
@endsection
