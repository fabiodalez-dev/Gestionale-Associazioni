@extends('area-soci.layouts.app')

@section('title', $discussione->titolo.' - Discussioni')

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
        <div>
            <a href="{{ route('area-soci.discussioni.spazio', $discussione->spazio_id) }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>{{ $discussione->spazio?->nome }}</a>
            <h1 class="h2 mb-0">{{ $discussione->titolo }}</h1>
        </div>
        <div class="d-flex gap-2">
            @if ($discussione->socio_id === $socio->id && \Illuminate\Support\Facades\Route::has('area-soci.proposte'))
                <a href="{{ route('area-soci.proposte', ['da_discussione' => $discussione->id]) }}" class="btn btn-outline-secondary btn-sm" title="Trasforma la discussione in una proposta formale"><i class="bi bi-lightbulb me-1"></i>Fanne una proposta</a>
            @endif
            @if ($segue)
                <form method="POST" action="{{ route('area-soci.discussioni.non-seguire', $discussione) }}">@csrf @method('DELETE')<button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bell-slash me-1"></i>Non seguire</button></form>
            @else
                <form method="POST" action="{{ route('area-soci.discussioni.segui', $discussione) }}">@csrf<button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-bell me-1"></i>Segui</button></form>
            @endif
        </div>
    </div>

    @foreach ($messaggi as $messaggio)
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                    <span class="fw-semibold small">{{ $messaggio->autore?->nome_completo }}</span>
                    <span class="small text-muted">{{ $messaggio->created_at?->format('d/m/Y H:i') }}</span>
                </div>
                <div>{!! $messaggio->testoHtml() !!}</div>
                @if ($messaggio->socio_id !== $socio->id)
                    <details class="mt-2">
                        <summary class="small text-muted">Segnala</summary>
                        <form method="POST" action="{{ route('area-soci.discussioni.segnala', $messaggio) }}" class="d-flex gap-2 mt-2">
                            @csrf
                            <input type="text" name="motivo" class="form-control form-control-sm" maxlength="500" placeholder="Perché? (facoltativo)" aria-label="Motivo della segnalazione">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Segnala</button>
                        </form>
                    </details>
                @endif
            </div>
        </div>
    @endforeach

    {{ $messaggi->links() }}

    @if ($discussione->chiusa)
        <p class="text-muted"><i class="bi bi-lock me-1"></i>La discussione è chiusa alle risposte.</p>
    @elseif ($bloccoScrittura)
        <p class="small text-muted">{{ $bloccoScrittura }}</p>
    @else
        <form method="POST" action="{{ route('area-soci.discussioni.rispondi', $discussione) }}" class="card card-body">
            @csrf
            <label class="form-label" for="rispTesto">Rispondi</label>
            <textarea name="testo" id="rispTesto" class="form-control mb-3" rows="4" maxlength="5000" required>{{ old('testo') }}</textarea>
            <div><button type="submit" class="btn btn-primary">Invia</button></div>
        </form>
    @endif
@endsection
