@if ($eventi->isEmpty())
    <div class="text-center text-muted py-5">
        <i class="bi bi-calendar-x display-5 d-block mb-2"></i>
        @if ($filtriAttivi)
            Nessun evento corrisponde ai filtri scelti.
            <div class="mt-2"><a href="{{ route('pubblico.eventi.index', $associazione->slug) }}" data-azzera-filtri>Mostra tutti gli eventi</a></div>
        @else
            Nessun evento in programma al momento. Torna a trovarci presto!
        @endif
    </div>
@else
    <div class="row g-4">
        @foreach ($eventi as $evento)
            <div class="col-12 col-md-6 col-lg-4">
                @include('pubblico.eventi._card', ['evento' => $evento, 'associazione' => $associazione])
            </div>
        @endforeach
    </div>
@endif
