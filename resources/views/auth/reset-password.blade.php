@extends('layouts.guest')

@section('title', 'Nuova password - Gestione Associazioni')
@section('brand-icon', 'bi-shield-lock-fill')
@section('subheading', 'Scegli una nuova password')

@section('content')
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="{{ old('email', $request->query('email')) }}" required autocomplete="username" readonly>
        </div>
        @include('formello.campi', ['form' => new \App\Forms\ImpostaPasswordForm(\App\Models\Utente::class, ['campi_utente' => ['email']])])
        <button type="submit" class="btn-login">Salva nuova password</button>
        <p class="small text-body-secondary mt-3 mb-0">Dopo il salvataggio verrai disconnesso da tutti i dispositivi.</p>
    </form>
@endsection

@section('footer')
    <a class="login-footer-link" href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Torna al login</a>
@endsection
