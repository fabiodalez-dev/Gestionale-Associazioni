<div class="form-check @if ($interruttore) form-switch @endif">
    @if ($inviaZero)
        <input type="hidden" name="{{ $name }}" value="0">
    @endif
    <input class="form-check-input @if ($errors) is-invalid @endif" type="checkbox" name="{{ $name }}" value="1" @checked($checked)
        @include('formello::widgets.bootstrap5._attributi', ['attributi' => $config['attributes'], 'escludi' => ['class', 'type', 'value']])>
    <label class="form-check-label" for="{{ $config['attributes']['id'] }}">
        {{ $label }}
        @if (filled($config['link_url'] ?? null))
            <a href="{{ $config['link_url'] }}" target="_blank" rel="noopener">{{ $config['link_testo'] ?? $config['link_url'] }}</a>
        @endif
        @if (! empty($config['attributes']['required']))<span class="text-danger ms-1" aria-hidden="true">*</span>@endif
    </label>
    @include('formello::widgets.bootstrap5._feedback')
</div>
