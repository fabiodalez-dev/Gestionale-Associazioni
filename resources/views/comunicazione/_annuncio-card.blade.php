{{-- Card di un annuncio in bacheca (f.7): usata dalla pagina Novità pubblica,
     dalla sezione Novità della home e dalla bacheca dell'area soci.
     corpo_html è purificato al salvataggio (EmailHtmlSanitizer). --}}
<div class="card mb-3 {{ $annuncio->in_evidenza ? 'border-warning' : '' }}">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <h2 class="h5 mb-1">{{ $annuncio->titolo }}</h2>
            <div>
                @if ($annuncio->in_evidenza)
                    <span class="badge bg-warning text-dark"><i class="bi bi-star-fill me-1"></i>In evidenza</span>
                @endif
                @if ($annuncio->solo_soci)
                    <span class="badge bg-primary"><i class="bi bi-person-badge me-1"></i>Solo soci</span>
                @endif
            </div>
        </div>
        @if ($annuncio->pubblicato_dal)
            <p class="text-muted small mb-2">
                <i class="bi bi-calendar3 me-1"></i>{{ $annuncio->pubblicato_dal->format('d/m/Y') }}
            </p>
        @endif
        <div class="annuncio-corpo">{!! $annuncio->corpo_html !!}</div>
    </div>
</div>
