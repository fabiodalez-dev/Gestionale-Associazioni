@extends('pubblico.layouts.app')

@section('title', 'Eventi — '.$associazione->nome)

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center mb-4 gap-2">
        <h1 class="h3 mb-0">Eventi in programma</h1>
        @if (Route::has('pubblico.calendario'))
            <a href="{{ route('pubblico.calendario', $associazione->slug) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-calendar3 me-1"></i>Calendario
            </a>
        @endif
    </div>

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
