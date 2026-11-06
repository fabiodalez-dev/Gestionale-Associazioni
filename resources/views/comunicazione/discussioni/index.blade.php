@extends('layouts.app')

@section('title', 'Discussioni tra soci')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <h1 class="h2"><i class="bi bi-chat-square-text me-2"></i>Discussioni tra soci</h1>
    @can('create', \App\Models\DiscussioneSpazio::class)
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#nuovoSpazio" aria-expanded="false" aria-controls="nuovoSpazio"><i class="bi bi-plus-lg me-1"></i>Nuovo spazio</button>
    @endcan
</div>

@include('partials.flash')

@can('create', \App\Models\DiscussioneSpazio::class)
<div class="collapse mb-4 @if ($errors->hasAny(['nome', 'tag_id'])) show @endif" id="nuovoSpazio">
    <form method="POST" action="{{ route('discussioni.spazi.store') }}" class="card card-body">
        @csrf
        @include('comunicazione.discussioni._spazio-campi', ['spazio' => null, 'tags' => $tags, 'prefisso' => 'nuovo'])
        <div><button type="submit" class="btn btn-primary btn-sm">Crea spazio</button></div>
    </form>
</div>
@endcan

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-flag me-2"></i>Segnalazioni da valutare <span class="badge bg-danger ms-1">{{ $segnalazioni->count() }}</span></div>
            <ul class="list-group list-group-flush">
                @forelse ($segnalazioni as $messaggio)
                    <li class="list-group-item">
                        <div class="small text-muted mb-1">
                            <a href="{{ route('discussioni.show', $messaggio->discussione_id) }}">{{ $messaggio->discussione?->titolo }}</a>
                            · {{ $messaggio->autore?->nome_completo }} · {{ $messaggio->created_at?->format('d/m/Y H:i') }}
                        </div>
                        <div class="mb-2">{!! $messaggio->testoHtml() !!}</div>
                        <div class="small text-muted mb-2">
                            {{ $messaggio->segnalazioni->count() }} {{ $messaggio->segnalazioni->count() === 1 ? 'segnalazione' : 'segnalazioni' }}:
                            {{ $messaggio->segnalazioni->pluck('motivo')->filter()->join(' · ') ?: 'senza motivo' }}
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <form method="POST" action="{{ route('discussioni.messaggi.nascondi', $messaggio) }}">@csrf<button type="submit" class="btn btn-sm btn-danger">Nascondi</button></form>
                            <form method="POST" action="{{ route('discussioni.messaggi.archivia', $messaggio) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary">Va bene così</button></form>
                            @if ($messaggio->autore)
                                <form method="POST" action="{{ route('discussioni.soci.sospendi', $messaggio->socio_id) }}" class="d-flex gap-1">
                                    @csrf
                                    <input type="text" name="motivo" class="form-control form-control-sm" maxlength="500" placeholder="Motivo" aria-label="Motivo della sospensione">
                                    <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">Sospendi autore</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nessuna segnalazione aperta.</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <div class="card-header">Discussioni recenti</div>
            <ul class="list-group list-group-flush">
                @forelse ($recenti as $discussione)
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <a href="{{ route('discussioni.show', $discussione) }}" class="text-decoration-none">
                            @if ($discussione->chiusa)<i class="bi bi-lock me-1"></i>@endif{{ $discussione->titolo }}
                        </a>
                        <span class="small text-muted text-nowrap">{{ $discussione->spazio?->nome }} · {{ $discussione->ultima_attivita_at?->format('d/m H:i') }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nessuna discussione.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header">Spazi</div>
            <ul class="list-group list-group-flush">
                @forelse ($spazi as $spazio)
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold {{ $spazio->archiviato ? 'text-muted' : '' }}">{{ $spazio->nome }}{{ $spazio->archiviato ? ' (archiviato)' : '' }}</span>
                            <span class="small text-muted">{{ $spazio->discussioni_count }} discussioni</span>
                        </div>
                        <div class="small text-muted">{{ $spazio->accesso === 'tag' ? 'Solo tag: '.($spazio->tag?->nome_tag ?? '—') : 'Tutti i soci attivi' }}</div>
                        @can('update', $spazio)
                            <details class="mt-1">
                                <summary class="small">Modifica</summary>
                                <form method="POST" action="{{ route('discussioni.spazi.update', $spazio) }}" class="mt-2">
                                    @csrf @method('PUT')
                                    @include('comunicazione.discussioni._spazio-campi', ['spazio' => $spazio, 'tags' => $tags, 'prefisso' => $spazio->id])
                                    <input type="hidden" name="archiviato" value="0">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="archiviato" value="1" id="arch-{{ $spazio->id }}" @checked($spazio->archiviato)>
                                        <label class="form-check-label small" for="arch-{{ $spazio->id }}">Archiviato (non più visibile ai soci)</label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary">Salva</button>
                                </form>
                            </details>
                        @endcan
                    </li>
                @empty
                    <li class="list-group-item text-muted">Crea il primo spazio, per esempio "Generale" o "Idee".</li>
                @endforelse
            </ul>
        </div>

        <div class="card mb-4">
            <div class="card-header">Soci sospesi dalla scrittura</div>
            <ul class="list-group list-group-flush">
                @forelse ($sospesi as $profilo)
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span><span class="fw-semibold">{{ $profilo->socio?->nome_completo }}</span> <span class="small text-muted">{{ $profilo->sospeso_motivo }}</span></span>
                        <form method="POST" action="{{ route('discussioni.soci.riattiva', $profilo->socio_id) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-success">Riattiva</button></form>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nessuno.</li>
                @endforelse
            </ul>
        </div>

        @can('create', \App\Models\DiscussioneSpazio::class)
        <form method="POST" action="{{ route('discussioni.regolamento') }}" class="card card-body">
            @csrf
            <label class="form-label fw-semibold" for="regolamento">Regolamento</label>
            <textarea name="discussioni_regolamento" id="regolamento" class="form-control mb-2" rows="8" maxlength="10000" required>{{ old('discussioni_regolamento', $regolamento) }}</textarea>
            <div class="form-text mb-2">Ogni socio lo accetta prima di scrivere per la prima volta.</div>
            <div><button type="submit" class="btn btn-sm btn-primary">Salva il regolamento</button></div>
        </form>
        @endcan
    </div>
</div>
@endsection
