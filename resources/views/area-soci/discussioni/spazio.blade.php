@extends('area-soci.layouts.app')

@section('title', $spazio->nome.' - Discussioni')

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
        <div>
            <a href="{{ route('area-soci.discussioni') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Discussioni</a>
            <h1 class="h2 mb-0">{{ $spazio->nome }}</h1>
        </div>
        @if (! $bloccoScrittura)
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#nuovaDiscussione" aria-expanded="false" aria-controls="nuovaDiscussione">
                <i class="bi bi-plus-lg me-1"></i>Nuova discussione
            </button>
        @endif
    </div>

    @if ($bloccoScrittura)
        <p class="small text-muted">{{ $bloccoScrittura }} @if (str_contains($bloccoScrittura, 'regolamento'))<a href="{{ route('area-soci.discussioni') }}">Leggi il regolamento</a>@endif</p>
    @else
        <div class="collapse mb-4 @if ($errors->any() || old('titolo')) show @endif" id="nuovaDiscussione">
            <form method="POST" action="{{ route('area-soci.discussioni.store', $spazio) }}" class="card card-body">
                @csrf
                <label class="form-label" for="discTitolo">Titolo</label>
                <input type="text" name="titolo" id="discTitolo" class="form-control mb-2" maxlength="200" value="{{ old('titolo') }}" required>
                <label class="form-label" for="discTesto">Messaggio</label>
                <textarea name="testo" id="discTesto" class="form-control mb-3" rows="5" maxlength="5000" required>{{ old('testo') }}</textarea>
                <div><button type="submit" class="btn btn-primary">Pubblica</button></div>
            </form>
        </div>
    @endif

    <div class="list-group mb-3">
        @forelse ($discussioni as $discussione)
            <a href="{{ route('area-soci.discussioni.show', $discussione) }}" class="list-group-item list-group-item-action">
                <div class="d-flex justify-content-between flex-wrap gap-2">
                    <span class="fw-semibold">
                        @if ($discussione->fissata)<i class="bi bi-pin-angle-fill me-1" title="In evidenza"></i>@endif
                        @if ($discussione->chiusa)<i class="bi bi-lock me-1" title="Chiusa"></i>@endif
                        {{ $discussione->titolo }}
                    </span>
                    <span class="small text-muted">{{ $discussione->messaggi_count }} messaggi · {{ $discussione->ultima_attivita_at->format('d/m/Y H:i') }}</span>
                </div>
                <div class="small text-muted">di {{ $discussione->autore?->nome_completo }}</div>
            </a>
        @empty
            <div class="list-group-item text-muted text-center py-4">Ancora nessuna discussione. Aprila tu!</div>
        @endforelse
    </div>

    {{ $discussioni->links() }}
@endsection
