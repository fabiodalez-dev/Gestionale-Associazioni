<div>
    @include('formello::widgets.bootstrap5._etichetta')
    @php($gruppo = filled($config['prefix'] ?? null) || filled($config['suffix'] ?? null))
    @if ($gruppo)
        <div class="input-group has-validation">
            @if (filled($config['prefix'] ?? null))<span class="input-group-text">{{ $config['prefix'] }}</span>@endif
    @endif
    <input type="{{ $config['attributes']['type'] }}" name="{{ $name }}" value="{{ $value }}"
        class="form-control {{ $config['attributes']['class'] ?? '' }} @if ($errors) is-invalid @endif"
        @if (filled($config['help'] ?? null)) aria-describedby="{{ $config['attributes']['id'] }}-help" @endif
        @include('formello::widgets.bootstrap5._attributi', ['attributi' => $config['attributes'], 'escludi' => ['class', 'type']])>
    @if ($gruppo)
            @if (filled($config['suffix'] ?? null))<span class="input-group-text">{{ $config['suffix'] }}</span>@endif
        </div>
    @endif
    @include('formello::widgets.bootstrap5._feedback')
</div>
