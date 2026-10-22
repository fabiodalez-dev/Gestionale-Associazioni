@if (filled($label ?? null))
    <label for="{{ $config['attributes']['id'] }}" class="form-label">{{ $label }}@if (! empty($config['attributes']['required']))<span class="text-danger ms-1" aria-hidden="true">*</span>@endif</label>
@endif
