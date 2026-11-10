@php
    $slug = $associazione->slug;
    $descrizione = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $associazione->descrizione_pubblica)));
    $luogo = collect([$associazione->citta, $associazione->provincia ? '('.$associazione->provincia.')' : null])->filter()->join(' ');
@endphp
# {{ $associazione->nome }}

@if ($descrizione !== '')
> {!! $descrizione !!}

@endif
@if ($luogo !== '')
Sede: {!! $luogo !!}
@endif
@if (filled($associazione->email))
Email: {!! $associazione->email !!}
@endif

## Pagine principali

- [Home]({{ route('pubblico.home.show', $slug) }}): presentazione dell'associazione
- [Eventi]({{ route('pubblico.eventi.index', $slug) }}): prossimi eventi, con prenotazione online
- [Calendario]({{ route('pubblico.calendario', $slug) }}): calendario degli eventi, anche in formato ICS
- [Iscriviti]({{ route('pubblico.iscriviti', $slug) }}): modulo per diventare socio
- [Novità]({{ route('pubblico.novita', $slug) }}): annunci pubblici
- [Sostieni]({{ route('pubblico.dona.show', $slug) }}): donazioni
- [Trasparenza]({{ route('pubblico.trasparenza', $slug) }}): documenti e informazioni pubbliche

## Prossimi eventi

@forelse ($eventi as $evento)
- [{!! $evento->titolo !!}]({{ route('pubblico.eventi.show', [$slug, $evento->id]) }}): {{ $evento->data_evento->format('d/m/Y H:i') }}@if (filled($evento->luogo)), {!! $evento->luogo !!}@endif
@if ((float) $evento->prezzo > 0) (€ {{ number_format((float) $evento->prezzo, 2, ',', '.') }})@endif

@empty
Nessun evento in programma al momento.
@endforelse
