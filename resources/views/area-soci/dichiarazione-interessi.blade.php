@extends('area-soci.layouts.app')

@section('title', 'Conflitti di interesse - Area Riservata')

@section('content')
    <div class="pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-shield-check me-2"></i>Dichiarazione sui conflitti di interesse {{ $anno }}</h1>
    </div>

    @if (! $titolare)
        <p class="text-muted">La dichiarazione è richiesta a chi ricopre una carica nell'associazione. Quest'anno non risultano cariche a tuo nome.</p>
    @else
        <p class="text-muted">
            Chi ricopre una carica dichiara ogni anno gli interessi che potrebbero entrare in conflitto con quelli dell'associazione:
            ruoli in altre organizzazioni, attività professionali, rapporti economici con l'associazione, parenti coinvolti.
            La dichiarazione è riservata: la vede solo la segreteria.
        </p>

        @if ($dichiarazione)
            <div class="alert alert-success">Hai presentato la dichiarazione il {{ $dichiarazione->dichiarata_at->format('d/m/Y') }}. Puoi aggiornarla se qualcosa cambia.</div>
        @endif

        <form method="POST" action="{{ route('area-soci.dichiarazione-interessi.store') }}" class="card card-body mb-4">
            @csrf
            <input type="hidden" name="nessun_conflitto" value="0">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="nessun_conflitto" value="1" id="nessunConflitto"
                       @checked(old('nessun_conflitto', $dichiarazione?->nessun_conflitto))>
                <label class="form-check-label" for="nessunConflitto">Dichiaro di non avere interessi in conflitto con quelli dell'associazione</label>
            </div>
            <label class="form-label" for="interessi">Oppure descrivi gli interessi da dichiarare</label>
            <textarea name="interessi" id="interessi" class="form-control mb-3" rows="5" maxlength="5000">{{ old('interessi', $dichiarazione?->interessi) }}</textarea>
            <div><button type="submit" class="btn btn-primary">Invia la dichiarazione</button></div>
        </form>
    @endif

    @if ($precedenti->isNotEmpty())
        <h2 class="h6">Anni precedenti</h2>
        <ul class="list-group">
            @foreach ($precedenti as $precedente)
                <li class="list-group-item small">
                    <strong>{{ $precedente->anno }}</strong> —
                    {{ $precedente->nessun_conflitto ? 'Nessun conflitto' : \Illuminate\Support\Str::limit($precedente->interessi, 200) }}
                </li>
            @endforeach
        </ul>
    @endif
@endsection
