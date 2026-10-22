@extends('layouts.guest')

@section('title', 'Area Soci - Imposta password')
@section('brand-icon', 'bi-shield-lock')
@section('heading', 'Imposta la password')
@section('subheading', 'Area Soci')

@section('content')
    @if (! $tokenValido)
        <div class="alert-login-error" role="alert">
            Token non valido o scaduto. Richiedi un nuovo link dalla pagina di accesso.
        </div>
        <a href="{{ route('area-soci.login') }}" class="btn-login d-block text-center text-decoration-none">Vai al Login</a>
    @else
        <form method="POST" action="{{ route('area-soci.set-password.store', ['token' => $token]) }}">
            @csrf
            @include('formello.campi', ['form' => new \App\Forms\ImpostaPasswordForm(\App\Models\Socio::class, ['dati_utente' => $datiSocio ?? []])])
            <button type="submit" class="btn-login">Imposta password</button>
        </form>
    @endif
@endsection

@section('footer')
    <a href="{{ route('area-soci.login') }}" class="login-footer-link">Torna al login</a>
@endsection
