@extends('layouts.app')

@section('title', 'Allegati - '.session('app_name', config('app.name')))

@section('content')
    <div class="pb-3 mb-4 border-bottom">
        <a href="{{ $urlScheda }}" class="small"><i class="bi bi-arrow-left me-1"></i>Torna</a>
        <h1 class="h3 mb-0 mt-1">Allegati — {{ $etichetta }}: {{ $titolo }}</h1>
    </div>
    <div class="row"><div class="col-lg-8">
        @include('allegati._elenco', ['tipo' => $tipo, 'modello' => $modello])
    </div></div>
@endsection
