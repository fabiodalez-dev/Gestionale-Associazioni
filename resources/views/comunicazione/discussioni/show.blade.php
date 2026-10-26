@extends('layouts.app')

@section('title', $discussione->titolo)

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <div>
        <a href="{{ route('discussioni.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Discussioni · {{ $discussione->spazio?->nome }}</a>
        <h1 class="h2 mb-0">{{ $discussione->titolo }}</h1>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('discussioni.update', $discussione) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="fissata" value="{{ $discussione->fissata ? 0 : 1 }}">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pin-angle me-1"></i>{{ $discussione->fissata ? 'Togli dall\'evidenza' : 'Metti in evidenza' }}</button>
        </form>
        <form method="POST" action="{{ route('discussioni.update', $discussione) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="chiusa" value="{{ $discussione->chiusa ? 0 : 1 }}">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-lock me-1"></i>{{ $discussione->chiusa ? 'Riapri' : 'Chiudi alle risposte' }}</button>
        </form>
    </div>
</div>

@include('partials.flash')

@foreach ($discussione->messaggi as $messaggio)
    <div class="card mb-3 {{ $messaggio->nascosto_at ? 'border-danger opacity-75' : '' }}">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-2 small">
                <span class="fw-semibold">{{ $messaggio->autore?->nome_completo }}</span>
                <span class="text-muted">
                    {{ $messaggio->created_at?->format('d/m/Y H:i') }}
                    @if ($messaggio->segnalazioni_count > 0)<span class="badge bg-danger ms-1">{{ $messaggio->segnalazioni_count }} segnalazioni</span>@endif
                    @if ($messaggio->nascosto_at)<span class="badge bg-secondary ms-1">Nascosto</span>@endif
                </span>
            </div>
            <div class="mb-2">{!! $messaggio->testoHtml() !!}</div>
            @if ($messaggio->nascosto_at)
                <form method="POST" action="{{ route('discussioni.messaggi.ripristina', $messaggio) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-success">Ripristina</button></form>
            @else
                <form method="POST" action="{{ route('discussioni.messaggi.nascondi', $messaggio) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-danger">Nascondi</button></form>
            @endif
        </div>
    </div>
@endforeach
@endsection
