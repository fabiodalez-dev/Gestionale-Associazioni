@extends('pubblico.layouts.app')

@section('title', 'Richiesta di iscrizione — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card shadow-sm text-center" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-5">
                    <i class="bi bi-hourglass-split text-info display-4 d-block mb-3"></i>
                    <h1 class="h4 mb-2">Richiesta in attesa di approvazione</h1>
                    <p class="text-muted mb-1">
                        Hai inviato la richiesta di iscrizione il
                        <strong>{{ $richiesta->created_at->format('d/m/Y') }}</strong>.
                    </p>
                    <p class="text-muted mb-4">
                        L'associazione la sta valutando: riceverai una risposta via email
                        e potrai seguirne lo stato dalla tua area riservata.
                    </p>
                    <a href="{{ route('pubblico.account.dashboard', $associazione->slug) }}" class="btn btn-primary">
                        <i class="bi bi-speedometer2 me-1"></i> Vai alla tua area riservata
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
