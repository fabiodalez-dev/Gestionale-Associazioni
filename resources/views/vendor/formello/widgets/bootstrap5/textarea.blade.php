<div>
    @include('formello::widgets.bootstrap5._etichetta')
    <textarea name="{{ $name }}" class="form-control {{ $config['attributes']['class'] ?? '' }} @if ($errors) is-invalid @endif"
        @include('formello::widgets.bootstrap5._attributi', ['attributi' => $config['attributes'], 'escludi' => ['class']])>{{ $value }}</textarea>
    @include('formello::widgets.bootstrap5._feedback')
</div>
