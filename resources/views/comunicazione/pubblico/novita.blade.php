@extends('pubblico.layouts.app')

@section('title', 'Novità — '.$associazione->nome)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-megaphone me-2"></i>Novità</h1>
        <a href="{{ route('pubblico.home.show', $associazione->slug) }}" class="small">Torna alla home</a>
    </div>

    @if ($annunci->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-megaphone display-5 d-block mb-2"></i>
            Nessuna novità al momento. Torna a trovarci presto!
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
