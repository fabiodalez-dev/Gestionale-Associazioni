@extends('area-soci.layouts.app')

@section('title', $pagina->titolo.' - Area Riservata')

@section('content')
    <div class="pt-3 pb-2 mb-3 border-bottom">
        <a href="{{ route('area-soci.manuale') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Guide · {{ $pagina->sezione }}</a>
        <h1 class="h2 mb-0">{{ $pagina->titolo }}</h1>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            {!! $pagina->contenutoHtml() !!}
        </div>
    </div>

    @if ($pagina->elencoPassi() !== [])
        <h2 class="h6">Passi</h2>
        <ol class="list-group list-group-numbered mb-4">
            @foreach ($pagina->elencoPassi() as $passo)
                <li class="list-group-item">{{ $passo }}</li>
            @endforeach
        </ol>
    @endif
@endsection
