@extends('layouts.guest')

@section('title', 'Login - Gestione Associazioni')

@section('content')
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" placeholder="nome@associazione.it" required autofocus autocomplete="username">
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" placeholder="La tua password" required autocomplete="current-password">
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" @checked(old('remember'))>
            <label class="form-check-label small" for="remember">Ricordami per {{ intdiv((int) config('auth.guards.web.remember'), 1440) }} giorni</label>
        </div>
        <div class="mb-3">
            <a class="small text-decoration-none" href="{{ route('password.request') }}">Password dimenticata?</a>
        </div>
        <button type="submit" class="btn-login">Accedi</button>
    </form>
@endsection
