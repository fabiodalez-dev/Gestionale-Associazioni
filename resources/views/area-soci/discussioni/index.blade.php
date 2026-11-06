@extends('area-soci.layouts.app')

@section('title', 'Discussioni - Area Riservata')

@section('content')
    <div class="pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-chat-square-text me-2"></i>Discussioni</h1>
    </div>

    @if ($profilo->regolamento_accettato_at === null)
        <div class="card mb-4 border-primary">
            <div class="card-header fw-semibold">Prima di scrivere: il regolamento</div>
            <div class="card-body">
                <div class="mb-3" style="white-space: pre-line">{{ $regolamento }}</div>
                <form method="POST" action="{{ route('area-soci.discussioni.regolamento') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Ho letto e accetto</button>
                </form>
            </div>
        </div>
    @elseif ($profilo->sospeso_at)
        <div class="alert alert-warning">La moderazione ha sospeso la tua possibilità di scrivere{{ $profilo->sospeso_motivo ? ': '.$profilo->sospeso_motivo : '.' }} Puoi continuare a leggere.</div>
    @endif

    @forelse ($spazi as $spazio)
        <a href="{{ route('area-soci.discussioni.spazio', $spazio) }}" class="card card-body mb-3 text-decoration-none text-reset">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <h2 class="h6 mb-1">{{ $spazio->nome }}</h2>
                <span class="small text-muted">
                    {{ $spazio->discussioni_count }} {{ $spazio->discussioni_count === 1 ? 'discussione' : 'discussioni' }}
                    @if ($spazio->discussioni_max_ultima_attivita_at) · ultima attività {{ \Illuminate\Support\Carbon::parse($spazio->discussioni_max_ultima_attivita_at)->format('d/m/Y') }}@endif
                </span>
            </div>
            @if ($spazio->descrizione)<p class="small text-muted mb-0">{{ $spazio->descrizione }}</p>@endif
        </a>
    @empty
        <div class="text-center text-muted py-5">
            <i class="bi bi-chat-square-text display-5 d-block mb-2"></i>
            Non ci sono ancora spazi di discussione.
        </div>
    @endforelse
@endsection
