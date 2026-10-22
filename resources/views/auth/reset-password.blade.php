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
        <div class="mb-3">
            <label for="password" class="form-label">Nuova password</label>
            <input type="password" class="form-control" id="password" name="password" required autofocus autocomplete="new-password" maxlength="{{ \App\Support\PasswordPolicy::MAX_LENGTH }}">
            <div class="form-text">{{ \App\Support\PasswordPolicy::hint() }}</div>
        </div>
        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Conferma password</label>
            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn-login">Salva nuova password</button>
        <p class="small text-body-secondary mt-3 mb-0">Dopo il salvataggio verrai disconnesso da tutti i dispositivi.</p>
    </form>
@endsection

@section('footer')
    <a class="login-footer-link" href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Torna al login</a>
@endsection
