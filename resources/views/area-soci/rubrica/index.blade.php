@extends('area-soci.layouts.app')

@section('title', 'Rubrica dei soci - Area Riservata')

@php
    $campiScelti = old('campi', $scheda->campi_visibili ?? []);
@endphp

@section('content')
    <div class="pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-person-lines-fill me-2"></i>Rubrica dei soci</h1>
    </div>

    @if ($esclusione)
        <p class="text-muted">{{ $esclusione }}</p>
    @else
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">La mia scheda</span>
                @if ($scheda->attiva)
                    <span class="badge bg-success">Visibile agli altri soci</span>
                @else
                    <span class="badge bg-secondary">Non sei in rubrica</span>
                @endif
            </div>
            <div class="card-body">
                <p class="small text-muted">Scegli tu cosa mostrare: nome e cognome si vedono sempre, il resto solo se lo selezioni. Puoi uscire dalla rubrica quando vuoi.</p>
                <form method="POST" action="{{ route('area-soci.rubrica.salva') }}">
                    @csrf @method('PUT')
                    <div class="row g-2 mb-3">
                        @foreach ($campi as $valore => $etichetta)
                            <div class="col-6 col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="campi[]" value="{{ $valore }}" id="campo-{{ $valore }}" @checked(in_array($valore, $campiScelti, true))>
                                    <label class="form-check-label" for="campo-{{ $valore }}">{{ $etichetta }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <label class="form-label small" for="rubPres">Presentazione</label>
                    <textarea name="presentazione" id="rubPres" class="form-control mb-2" rows="2" maxlength="1000">{{ old('presentazione', $scheda->presentazione) }}</textarea>
                    <label class="form-label small" for="rubInt">Interessi e competenze, separati da virgola</label>
                    <input type="text" name="interessi" id="rubInt" class="form-control mb-3" maxlength="500" value="{{ old('interessi', implode(', ', $scheda->interessi ?? [])) }}" placeholder="Es. fotografia, grafica, patente C">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="consenso" value="1" id="rubConsenso" @checked($scheda->attiva) required>
                        <label class="form-check-label small" for="rubConsenso">{{ \App\Services\Comunicazione\RubricaService::TESTO_CONSENSO }}</label>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">{{ $scheda->attiva ? 'Aggiorna la scheda' : 'Entra in rubrica' }}</button>
                    </div>
                </form>
                @if ($scheda->attiva)
                    <form method="POST" action="{{ route('area-soci.rubrica.revoca') }}" class="mt-2" onsubmit="return confirm('Uscire dalla rubrica?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-link text-danger p-0">Esci dalla rubrica</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($blocco)
            <p class="text-muted">{{ $blocco }}</p>
        @else
            <form method="GET" action="{{ route('area-soci.rubrica') }}" class="row g-2 mb-3">
                <div class="col-md-4"><input type="text" name="nome" class="form-control" placeholder="Nome o cognome" value="{{ $filtri['nome'] ?? '' }}" aria-label="Nome o cognome"></div>
                <div class="col-md-3"><input type="text" name="citta" class="form-control" placeholder="Città" value="{{ $filtri['citta'] ?? '' }}" aria-label="Città"></div>
                <div class="col-md-3"><input type="text" name="interesse" class="form-control" placeholder="Interesse" value="{{ $filtri['interesse'] ?? '' }}" aria-label="Interesse"></div>
                <div class="col-md-2"><button type="submit" class="btn btn-outline-primary w-100">Cerca</button></div>
            </form>

            @forelse ($schede as $voce)
                @php $persona = $voce->socio; @endphp
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <h2 class="h6 mb-1">{{ $persona?->nome_completo }}</h2>
                            <span class="small text-muted">
                                @if ($voce->mostra('citta') && $persona?->citta){{ $persona->citta }}@endif
                                @if ($voce->mostra('sede') && $persona?->sede) · {{ $persona->sede->nome }}@endif
                            </span>
                        </div>
                        @if ($voce->mostra('presentazione') && $voce->presentazione)<p class="small mb-2">{{ $voce->presentazione }}</p>@endif
                        @if ($voce->mostra('interessi') && ! empty($voce->interessi))
                            <div class="mb-2">@foreach ($voce->interessi as $interesse)<span class="badge text-bg-light border me-1">{{ $interesse }}</span>@endforeach</div>
                        @endif
                        <div class="small mb-2">
                            @if ($voce->mostra('email') && $persona?->email)<a href="mailto:{{ $persona->email }}" class="me-3"><i class="bi bi-envelope me-1"></i>{{ $persona->email }}</a>@endif
                            @if ($voce->mostra('telefono') && $persona?->telefono)<a href="tel:{{ $persona->telefono }}"><i class="bi bi-telephone me-1"></i>{{ $persona->telefono }}</a>@endif
                        </div>
                        @if ($voce->socio_id !== $socio->id)
                            <details>
                                <summary class="small">Scrivi tramite la piattaforma</summary>
                                <form method="POST" action="{{ route('area-soci.rubrica.contatta', $voce) }}" class="mt-2">
                                    @csrf
                                    <input type="text" name="oggetto" class="form-control form-control-sm mb-2" maxlength="150" placeholder="Oggetto" required aria-label="Oggetto">
                                    <textarea name="testo" class="form-control form-control-sm mb-2" rows="3" maxlength="3000" required aria-label="Messaggio"></textarea>
                                    <div class="form-text mb-2">Il socio riceve il messaggio via email e può risponderti: tu non vedi il suo indirizzo, lui vedrà il tuo se risponde.</div>
                                    <button type="submit" class="btn btn-sm btn-primary">Invia</button>
                                </form>
                            </details>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-muted">Nessun socio corrisponde alla ricerca.</p>
            @endforelse

            {{ $schede->links() }}
        @endif
    @endif
@endsection
