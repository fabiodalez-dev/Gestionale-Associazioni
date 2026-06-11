@extends('pubblico.layouts.app')

@section('title', 'Diventa socio — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Diventa socio</h1>

    @include('pubblico.account._nav')

    <div class="card" style="max-width: 640px;">
        <div class="card-body">
            @if ($account->socio_id !== null)
                <div class="text-center py-3">
                    <i class="bi bi-patch-check-fill text-success display-5 d-block mb-2"></i>
                    <p class="fw-semibold mb-1">Sei già socio di {{ $associazione->nome }}!</p>
                    <a href="{{ route('pubblico.account.tessera', $associazione->slug) }}" class="btn btn-primary btn-sm mt-2">
                        Vai a tessera &amp; quote
                    </a>
                </div>
            @elseif ($account->richiesta_socio_at !== null)
                <div class="text-center py-3">
                    <i class="bi bi-hourglass-split text-info display-5 d-block mb-2"></i>
                    <p class="fw-semibold mb-1">Richiesta inviata il {{ $account->richiesta_socio_at->format('d/m/Y') }}</p>
                    <p class="text-muted mb-0">
                        L'associazione sta valutando la tua richiesta e ti contatterà.
                    </p>
                </div>
            @else
                <p class="text-muted">
                    Raccontaci perché vuoi diventare socio di {{ $associazione->nome }}:
                    la richiesta sarà inviata agli amministratori, che ti contatteranno.
                </p>

                <form method="POST" action="{{ route('pubblico.account.diventa-socio.store', $associazione->slug) }}">
                    @csrf

                    <div class="mb-3">
                        <label for="motivazione" class="form-label">Motivazione</label>
                        <textarea class="form-control @error('motivazione') is-invalid @enderror"
                                  id="motivazione" name="motivazione" rows="5" required maxlength="1000"
                                  placeholder="Scrivi qui la tua motivazione...">{{ old('motivazione') }}</textarea>
                        @error('motivazione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Invia richiesta
                    </button>
                </form>
            @endif
        </div>
    </div>
@endsection
