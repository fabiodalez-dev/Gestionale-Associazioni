@extends('area-soci.layouts.app')

@section('title', 'Bacheca - Area Riservata')

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-megaphone me-2"></i>Bacheca</h1>
    </div>

    @if ($annunci->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-megaphone display-5 d-block mb-2"></i>
            Nessun annuncio in bacheca al momento.
        </div>
    @else
        @foreach ($annunci as $annuncio)
            @include('comunicazione._annuncio-card', ['annuncio' => $annuncio])
        @endforeach

        <div class="d-flex justify-content-center mt-3">
            {{ $annunci->links() }}
        </div>
    @endif
@endsection
