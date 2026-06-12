@extends('pubblico.layouts.app')

@section('title', 'Sostienici — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="text-center mb-4">
                <h1 class="h3 fw-bold"><i class="bi bi-heart-fill text-danger me-2"></i>Sostieni {{ $associazione->nome }}</h1>
                <p class="text-muted">
                    Ogni contributo, anche piccolo, ci aiuta a portare avanti le nostre attività.
                    Riceverai la ricevuta della donazione via email.
                </p>
            </div>

            @if (session('checkout_error'))
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ session('checkout_error') }}
                </div>
            @endif

            @if (! $hasStripe && ! $hasPayPal)
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-1"></i>
                    Le donazioni online non sono al momento disponibili. Contatta l'associazione per altre modalità di donazione.
                </div>
            @else
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('pubblico.dona.store', $associazione->slug) }}">
                            @csrf
                            <x-honeypot />

                            <div class="mb-3">
                                <label for="campoImporto" class="form-label">Importo della donazione (&euro;) *</label>
                                <div class="d-flex gap-2 mb-2 flex-wrap">
                                    @foreach ([5, 10, 25, 50, 100] as $suggerito)
                                        <button type="button" class="btn btn-outline-primary btn-sm btn-importo" data-importo="{{ $suggerito }}">
                                            &euro; {{ $suggerito }}
                                        </button>
                                    @endforeach
                                </div>
                                <input type="number" step="0.01" min="{{ $importoMinimo }}" name="importo" id="campoImporto"
                                       class="form-control @error('importo') is-invalid @enderror"
                                       value="{{ old('importo') }}" placeholder="Importo libero (minimo € {{ $importoMinimo }})" required>
                                @error('importo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="campoNome" class="form-label">Il tuo nome</label>
                                <input type="text" name="donatore_nome" id="campoNome" maxlength="150"
                                       class="form-control @error('donatore_nome') is-invalid @enderror" value="{{ old('donatore_nome') }}">
                                @error('donatore_nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="campoEmail" class="form-label">Email (per la ricevuta) *</label>
                                <input type="email" name="donatore_email" id="campoEmail" maxlength="255"
                                       class="form-control @error('donatore_email') is-invalid @enderror" value="{{ old('donatore_email') }}" required>
                                @error('donatore_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="anonimo" id="campoAnonimo" value="1" @checked(old('anonimo'))>
                                <label class="form-check-label" for="campoAnonimo">
                                    Donazione anonima <span class="text-muted small">(il tuo nome non sarà mostrato)</span>
                                </label>
                                @error('anonimo')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-2 fw-semibold">Scegli come donare *</div>
                            @error('gateway')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                            <div class="d-grid gap-2">
                                @if ($hasStripe)
                                    <button type="submit" name="gateway" value="stripe" class="btn btn-primary btn-lg">
                                        <i class="bi bi-credit-card me-2"></i>Dona con carta (Stripe)
                                    </button>
                                @endif
                                @if ($hasPayPal)
                                    <button type="submit" name="gateway" value="paypal" class="btn btn-outline-primary btn-lg">
                                        <i class="bi bi-paypal me-2"></i>Dona con PayPal
                                    </button>
                                @endif
                            </div>

                            <p class="text-muted small mt-3 mb-0">
                                Il pagamento avviene in modo sicuro sul circuito scelto: non conserviamo i dati della tua carta.
                            </p>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var campo = document.getElementById('campoImporto');
    document.querySelectorAll('.btn-importo').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (campo) campo.value = btn.dataset.importo;
        });
    });
})();
</script>
@endpush
