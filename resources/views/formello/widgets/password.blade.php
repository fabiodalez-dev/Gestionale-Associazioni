<div>
    @include('formello::widgets.bootstrap5._etichetta')
    <div class="input-group has-validation">
        <input type="password" name="{{ $name }}" value=""
            class="form-control {{ $config['attributes']['class'] ?? '' }} @if ($errors) is-invalid @endif"
            @if (filled($config['help'] ?? null)) aria-describedby="{{ $config['attributes']['id'] }}-help" @endif
            @include('formello::widgets.bootstrap5._attributi', ['attributi' => $config['attributes'], 'escludi' => ['class']])>
        <button type="button" class="btn btn-outline-secondary" data-toggle-password="{{ $config['attributes']['id'] }}" aria-label="Mostra password" aria-pressed="false">
            <i class="bi bi-eye"></i>
        </button>
    </div>
    @if ($misuratore)
        <div class="mt-2" data-password-meter-for="{{ $config['attributes']['id'] }}" hidden aria-live="polite">
            <div class="progress" style="height: 4px;" role="presentation">
                <div class="progress-bar" data-meter-bar style="width: 0%"></div>
            </div>
            <div class="small text-body-secondary mt-1" data-meter-text></div>
        </div>
    @endif
    @include('formello::widgets.bootstrap5._feedback')
</div>
@once
    @push('scripts')
        <script src="{{ asset('assets/vendor/zxcvbn/password-strength.min.js') }}" defer></script>
    @endpush
@endonce
