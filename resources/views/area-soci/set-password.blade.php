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
            <div class="mb-3">
                <label for="password" class="form-label">Nuova password</label>
                <input type="password" class="form-control" id="password" name="password" required autofocus autocomplete="new-password" minlength="10" placeholder="Minimo 10 caratteri">
                <div class="form-text">{{ \App\Support\PasswordPolicy::hint() }}</div>
            </div>
            <div class="mb-3">
                <label for="password_confirmation" class="form-label">Conferma password</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" minlength="10" placeholder="Ripeti la password">
            </div>
            <button type="submit" class="btn-login">Imposta password</button>
        </form>
    @endif
@endsection

@section('footer')
    <a href="{{ route('area-soci.login') }}" class="login-footer-link">Torna al login</a>
@endsection
