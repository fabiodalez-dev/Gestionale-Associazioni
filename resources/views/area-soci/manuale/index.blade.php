@extends('area-soci.layouts.app')

@section('title', 'Guide - Area Riservata')

@section('content')
    <div class="pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-book me-2"></i>Guide</h1>
    </div>

    @forelse ($perSezione as $sezione => $pagine)
        <h2 class="h6 text-uppercase text-muted mt-4">{{ $sezione }}</h2>
        <div class="list-group mb-3">
            @foreach ($pagine as $pagina)
                <a href="{{ route('area-soci.manuale.show', $pagina) }}" class="list-group-item list-group-item-action">{{ $pagina->titolo }}</a>
            @endforeach
        </div>
    @empty
        <div class="text-center text-muted py-5">
            <i class="bi bi-book display-5 d-block mb-2"></i>
            Nessuna guida disponibile per ora.
        </div>
    @endforelse
@endsection
