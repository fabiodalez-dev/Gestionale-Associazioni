@extends('pubblico.layouts.app')

@section('title', $associazione->nome.' — Eventi e prenotazioni')

@section('content')
    {{-- Hero brandizzato --}}
    <section class="pubblico-hero p-4 p-md-5 mb-5 text-center text-md-start">
        <div class="row align-items-center g-4">
            <div class="col-md-8">
                <h1 class="display-6 fw-bold mb-2">{{ $associazione->nome }}</h1>
                @if (filled($associazione->descrizione_pubblica))
                    <p class="lead text-muted mb-3">{{ $associazione->descrizione_pubblica }}</p>
                @else
                    <p class="lead text-muted mb-3">Scopri i nostri eventi e prenota il tuo posto in pochi clic.</p>
                @endif
                <a href="{{ route('pubblico.eventi.index', $associazione->slug) }}" class="btn btn-primary">
                    <i class="bi bi-calendar-event me-1"></i> Scopri gli eventi
                </a>
                @if ($associazione->iscrizioni()->iscrizioni_socio_aperte)
                    <a href="{{ route('pubblico.iscriviti', $associazione->slug) }}" class="btn btn-outline-primary ms-2">
                        <i class="bi bi-person-heart me-1"></i> Diventa socio
                    </a>
                @endif
                @guest('account')
                    <a href="{{ route('pubblico.registrazione', $associazione->slug) }}" class="btn btn-outline-secondary ms-2">
                        Crea un account
                    </a>
                @endguest
            </div>
            @if (filled($associazione->logoUrl()))
                <div class="col-md-4 text-center d-none d-md-block">
                    <img src="{{ $associazione->logoUrl('miniatura') }}" alt="Logo {{ $associazione->nome }}"
                         class="img-fluid" style="max-height: 160px;">
                </div>
            @endif
        </div>
    </section>

    {{-- Novità in bacheca (f.7 piano Gestione 2 — modulo comunicazione) --}}
    @includeIf('comunicazione.pubblico._novita-home')

    {{-- I nostri sostenitori (f.22 piano "Organizzazione 3" — modulo relazioni) --}}
    @includeIf('relazioni.pubblico._sostenitori-home')

    {{-- Prossimi eventi --}}
    <section>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h4 mb-0">Prossimi eventi</h2>
            <a href="{{ route('pubblico.eventi.index', $associazione->slug) }}" class="small">Vedi tutti</a>
        </div>

        @if ($prossimiEventi->isEmpty())
            <div class="text-center text-muted py-5">
                <i class="bi bi-calendar-x display-5 d-block mb-2"></i>
                Nessun evento in programma al momento. Torna a trovarci presto!
            </div>
        @else
            <div class="row g-4">
                @foreach ($prossimiEventi as $evento)
                    <div class="col-12 col-md-6 col-lg-4">
                        @include('pubblico.eventi._card', ['evento' => $evento, 'associazione' => $associazione])
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
