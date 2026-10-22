<div>
    @include('formello::widgets.bootstrap5._etichetta')
    <select name="{{ $name }}" class="form-select {{ $config['attributes']['class'] ?? '' }} @if ($errors) is-invalid @endif"
        @include('formello::widgets.bootstrap5._attributi', ['attributi' => $config['attributes'], 'escludi' => ['class']])>
        @if (array_key_exists('placeholder', $config))
            <option value="">{{ $config['placeholder'] }}</option>
        @endif
        @foreach ($choices as $valoreOpzione => $etichettaOpzione)
            <option value="{{ $valoreOpzione }}" @selected(in_array((string) $valoreOpzione, array_map('strval', (array) $value), true))>{{ $etichettaOpzione }}</option>
        @endforeach
    </select>
    @include('formello::widgets.bootstrap5._feedback')
</div>
