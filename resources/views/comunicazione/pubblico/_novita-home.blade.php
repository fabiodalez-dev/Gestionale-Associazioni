{{-- Sezione "Novità" della home pubblica (f.7 piano Gestione 2).
     Autonoma: calcola i propri dati per non toccare HomeController
     (stesso pattern di eventi-vita._top-partecipanti). --}}
@php
    $ultimiAnnunciHome = \App\Models\Annuncio::query()
        ->where('associazione_id', $associazione->id)
        ->pubblici()
        ->orderByDesc('in_evidenza')
        ->orderByDesc('pubblicato_dal')
        ->limit(3)
        ->get();
@endphp

@if ($ultimiAnnunciHome->isNotEmpty())
    <section class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h4 mb-0"><i class="bi bi-megaphone me-2"></i>Novità</h2>
            @if (\Illuminate\Support\Facades\Route::has('pubblico.novita'))
                <a href="{{ route('pubblico.novita', $associazione->slug) }}" class="small">Vedi tutte</a>
            @endif
        </div>
        @foreach ($ultimiAnnunciHome as $annuncio)
            @include('comunicazione._annuncio-card', ['annuncio' => $annuncio])
        @endforeach
    </section>
@endif
