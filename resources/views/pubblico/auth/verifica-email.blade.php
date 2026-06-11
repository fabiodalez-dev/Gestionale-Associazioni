@extends('pubblico.layouts.app')

@section('title', 'Verifica la tua email — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="card shadow-sm text-center" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <i class="bi bi-envelope-check display-4 text-primary d-block mb-3"></i>
                    <h1 class="h4 mb-2">Verifica il tuo indirizzo email</h1>
                    <p class="text-muted">
                        Ti abbiamo inviato un'email con il link di verifica all'indirizzo
                        <strong>{{ auth('account')->user()?->email }}</strong>.
                        Controlla la posta in arrivo (e la cartella spam).
                    </p>

                    <form method="POST" action="{{ route('pubblico.verifica-email.reinvia', $associazione->slug) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reinvia email di verifica
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
