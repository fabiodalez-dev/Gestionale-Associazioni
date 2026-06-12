{{--
    Pulsante "Rinnova per il {anno}" nell'area account del mini-sito
    pubblico (f.1 piano Vita sociale). Visibile solo per gli account
    collegati a un socio attivo senza tessera per l'anno corrente.
    Richiede $associazione e $socio in scope.
--}}
@php
    /** @var \App\Services\Rinnovi\RinnovoService $rinnovoService */
    $rinnovoService = app(\App\Services\Rinnovi\RinnovoService::class);
    $annoRinnovo = $rinnovoService->annoCorrente();
@endphp

@if ($rinnovoService->puoRinnovare($socio))
    <form method="POST" action="{{ route('pubblico.account.rinnova', $associazione) }}" class="mt-3 mb-0">
        @csrf
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-arrow-repeat me-1"></i>Rinnova per il {{ $annoRinnovo }}
        </button>
        <div class="form-text">Paghi online la quota {{ $annoRinnovo }} e la tessera viene emessa automaticamente.</div>
    </form>
@endif
