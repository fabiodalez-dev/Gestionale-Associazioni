{{-- Messaggi flash di sessione: i successi si auto-nascondono, gli errori restano visibili --}}
@foreach (['success' => 'success', 'message' => 'info', 'warning' => 'warning', 'error' => 'danger', 'danger' => 'danger'] as $key => $type)
    @if (session($key))
        <div class="alert alert-{{ $type }} alert-dismissible fade show mt-3 {{ in_array($type, ['danger', 'warning'], true) ? '' : 'auto-dismiss' }}" role="alert">
            {{ session($key) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    @endif
@endforeach

@if ($errors->any() && ! request()->routeIs('login'))
    <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $errore)
                <li>{{ $errore }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
    </div>
@endif

@once
    @push('scripts')
        <script>
            // I messaggi di successo spariscono dopo 5 secondi; gli errori restano visibili.
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.alert.auto-dismiss').forEach(function (el) {
                    setTimeout(function () {
                        el.classList.remove('show');
                        setTimeout(function () { el.remove(); }, 300);
                    }, 5000);
                });
            });
        </script>
    @endpush
@endonce
