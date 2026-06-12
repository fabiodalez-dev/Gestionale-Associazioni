{{-- Card di un annuncio in bacheca (f.7): usata dalla pagina Novità pubblica,
     dalla sezione Novità della home e dalla bacheca dell'area soci.
     corpo_html è purificato al salvataggio (EmailHtmlSanitizer).
     Per le convocazioni (addendum) mostra data/luogo assemblea, ordine del
     giorno e il bottone di conferma presenza verso la pagina pubblica
     dell'evento collegato (solo se pubblicato). --}}
<div class="card mb-3 {{ $annuncio->in_evidenza ? 'border-warning' : '' }}">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <h2 class="h5 mb-1">{{ $annuncio->titolo }}</h2>
            <div>
                @if ($annuncio->isConvocazione())
                    <span class="badge bg-danger"><i class="bi bi-people me-1"></i>Convocazione assemblea</span>
                @endif
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

        @if ($annuncio->isConvocazione())
            <div class="border rounded p-3 mt-3">
                <p class="mb-1">
                    <i class="bi bi-calendar-event me-1"></i>
                    <strong>Assemblea:</strong>
                    {{ $annuncio->data_assemblea?->format('d/m/Y H:i') ?? 'data da definire' }}
                    @if (filled($annuncio->luogo_assemblea))
                        — <i class="bi bi-geo-alt me-1"></i>{{ $annuncio->luogo_assemblea }}
                    @endif
                </p>
                @if (filled($annuncio->ordine_del_giorno))
                    <p class="mb-1 mt-2"><strong>Ordine del giorno:</strong></p>
                    <p class="mb-0 small">{!! nl2br(e($annuncio->ordine_del_giorno)) !!}</p>
                @endif
                @php
                    $eventoConvocazione = $annuncio->evento;
                    $eventoPubblicato = $eventoConvocazione !== null
                        && $eventoConvocazione->stato_pubblicazione === \App\Models\Evento::STATO_PUBBLICATO;
                @endphp
                @if ($eventoPubblicato && \Illuminate\Support\Facades\Route::has('pubblico.eventi.show'))
                    <a href="{{ route('pubblico.eventi.show', [$annuncio->associazione->slug, $eventoConvocazione->id]) }}"
                       class="btn btn-sm btn-primary mt-3">
                        <i class="bi bi-check2-circle me-1"></i>Conferma la tua presenza
                    </a>
                @endif
            </div>
        @endif
    </div>
</div>
