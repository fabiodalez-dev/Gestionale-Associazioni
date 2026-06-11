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
            @elseif ($richiesta !== null && $richiesta->isInAttesa())
                <div class="text-center py-3">
                    <i class="bi bi-hourglass-split text-info display-5 d-block mb-2"></i>
                    <p class="fw-semibold mb-1">Richiesta inviata il {{ $richiesta->created_at->format('d/m/Y') }}</p>
                    <p class="text-muted mb-2">
                        La tua richiesta di iscrizione è in attesa di approvazione:
                        riceverai una risposta via email.
                    </p>
                    <a href="{{ route('pubblico.iscriviti', $associazione->slug) }}" class="btn btn-outline-secondary btn-sm">
                        Vedi lo stato della richiesta
                    </a>
                </div>
            @elseif ($richiesta !== null && $richiesta->isRifiutata())
                <div class="text-center py-3">
                    <i class="bi bi-x-circle text-danger display-5 d-block mb-2"></i>
                    <p class="fw-semibold mb-1">Richiesta rifiutata</p>
                    @if ($richiesta->motivo_rifiuto)
                        <p class="text-muted mb-2">Motivo: {{ $richiesta->motivo_rifiuto }}</p>
                    @endif
                    <a href="{{ route('pubblico.iscriviti', $associazione->slug) }}" class="btn btn-primary btn-sm mt-1">
                        <i class="bi bi-arrow-repeat me-1"></i>Invia una nuova richiesta
                    </a>
                </div>
            @elseif ($richiesta !== null && $richiesta->isApprovata())
                <div class="text-center py-3">
                    <i class="bi bi-patch-check text-success display-5 d-block mb-2"></i>
                    <p class="fw-semibold mb-1">Richiesta approvata!</p>
                    <p class="text-muted mb-0">
                        La tua iscrizione è stata approvata: l'associazione sta
                        completando il collegamento della tua scheda socio.
                    </p>
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
                {{-- Fallback (la GET reindirizza già al form di iscrizione) --}}
                <div class="text-center py-3">
                    <p class="text-muted mb-2">Vuoi far parte di {{ $associazione->nome }}?</p>
                    <a href="{{ route('pubblico.iscriviti', $associazione->slug) }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-person-heart me-1"></i>Iscriviti
                    </a>
                </div>
            @endif
        </div>
    </div>
@endsection
