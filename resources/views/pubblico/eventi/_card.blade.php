{{-- Card evento del catalogo pubblico --}}
@php
    /** @var \App\Models\Evento $evento */
    /** @var \App\Models\Associazione $associazione */
    $posti = $evento->postiDisponibili(); // best-effort (sola lettura)
    $esaurito = $posti !== null && $posti <= 0;
@endphp
<div class="card evento-card h-100 shadow-sm position-relative">
    @if (filled($evento->immagine_url))
        <img src="{{ $evento->immagine_url }}" class="evento-card-img" alt="{{ $evento->titolo }}">
    @else
        <div class="evento-card-placeholder">
            <i class="bi bi-calendar-event display-5 text-primary"></i>
        </div>
    @endif
    <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
            <h3 class="h5 card-title mb-0">{{ $evento->titolo }}</h3>
            @if ($evento->solo_soci)
                <span class="badge text-bg-warning text-nowrap" title="Prenotabile solo dai soci attivi">
                    <i class="bi bi-person-badge me-1"></i>Riservato ai soci
                </span>
            @endif
            @if ($esaurito)
                @if ($evento->lista_attesa_attiva)
                    <span class="badge text-bg-warning text-nowrap">Lista d'attesa</span>
                @else
                    <span class="badge text-bg-danger text-nowrap">Esaurito</span>
                @endif
            @endif
        </div>
        <p class="text-muted small mb-2">
            <i class="bi bi-calendar3 me-1"></i>{{ $evento->data_evento->format('d/m/Y H:i') }}
            @if (filled($evento->luogo))
                <span class="mx-1">&middot;</span><i class="bi bi-geo-alt me-1"></i>{{ $evento->luogo }}
            @endif
        </p>
        <p class="mb-3">
            @if ((float) $evento->prezzo > 0)
                <span class="fw-semibold">&euro; {{ number_format((float) $evento->prezzo, 2, ',', '.') }}</span>
            @else
                <span class="badge text-bg-success">Gratuito</span>
            @endif
            @if ($posti !== null && ! $esaurito)
                <span class="text-muted small ms-2">{{ $posti }} {{ $posti === 1 ? 'posto rimasto' : 'posti rimasti' }}</span>
            @endif
        </p>
        <a href="{{ route('pubblico.eventi.show', ['associazione' => $associazione->slug, 'evento' => $evento->id]) }}"
           class="btn btn-outline-primary btn-sm mt-auto stretched-link">Dettagli</a>
    </div>
</div>
