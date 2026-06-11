@extends('pubblico.layouts.app')

@section('title', 'Iscrizioni chiuse — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card shadow-sm text-center" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-5">
                    <i class="bi bi-door-closed text-muted display-4 d-block mb-3"></i>
                    <h1 class="h4 mb-2">Le iscrizioni sono chiuse</h1>
                    <p class="text-muted mb-4">
                        Al momento {{ $associazione->nome }} non accetta nuove iscrizioni come socio.
                        Riprova più avanti o contatta direttamente l'associazione.
                    </p>
                    <a href="{{ route('pubblico.home.show', $associazione->slug) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-house me-1"></i> Torna alla home
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
