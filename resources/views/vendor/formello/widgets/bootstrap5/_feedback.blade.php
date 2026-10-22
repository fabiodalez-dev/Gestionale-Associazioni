@if (filled($config['help'] ?? null))
    <div class="form-text" id="{{ $config['attributes']['id'] }}-help">{{ $config['help'] }}</div>
@endif
@if ($errors)
    <div class="invalid-feedback d-block">{{ $errors[0] }}</div>
@endif
