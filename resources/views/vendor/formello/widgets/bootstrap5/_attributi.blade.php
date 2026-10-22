{{-- Attributi HTML del campo: i booleani diventano attributi senza valore (required) o vengono omessi se false --}}
@foreach ($attributi as $attr => $valore)
    @continue(in_array($attr, $escludi ?? [], true))
    @if ($valore === true)
        {{ $attr }}
    @elseif ($valore !== false && $valore !== null)
        {{ $attr }}="{{ $valore }}"
    @endif
@endforeach
