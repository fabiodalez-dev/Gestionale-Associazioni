@extends('pubblico.layouts.app')

@section('title', 'Registrati — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-6">
            <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Crea il tuo account</h1>
                    <p class="text-muted small mb-4">
                        Con l'account di {{ $associazione->nome }} puoi prenotare gli eventi e gestire le tue prenotazioni.
                    </p>

                    <form method="POST" action="{{ route('pubblico.registrazione.store', $associazione->slug) }}" novalidate>
                        @csrf
                        <x-honeypot />

                        @include('formello.campi', ['form' => $form])

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-person-plus me-1"></i> Registrati
                        </button>
                    </form>

                    <hr>

                    <p class="small mb-0">
                        Hai già un account?
                        <a href="{{ route('pubblico.login', $associazione->slug) }}">Accedi</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
