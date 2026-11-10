# {!! config('app.name') !!}

> Piattaforma di gestione per associazioni: ogni associazione ha un mini-sito pubblico con eventi, iscrizioni, novità, donazioni e trasparenza.

## Associazioni

@forelse ($associazioni as $associazione)
- [{!! $associazione->nome !!}]({{ route('pubblico.home.show', $associazione->slug) }})@if (filled($associazione->citta)), {!! $associazione->citta !!}@endif: dettagli in {{ route('pubblico.llms', $associazione->slug) }}
@empty
Nessuna associazione con mini-sito pubblico.
@endforelse
