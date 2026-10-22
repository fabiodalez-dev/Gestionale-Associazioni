@extends('layouts.guest')

@section('title', 'Imposta la password')
@section('heading', 'Benvenuto!')
@section('subheading', 'Imposta la password per il tuo account amministratore')
@section('brand-icon', 'bi-key-fill')

@section('content')
    <p class="small text-muted mb-3">
        Account: <strong>{{ $utente->email }}</strong>
        @if ($utente->associazione)
            — {{ $utente->associazione->nome }}
        @endif
    </p>
    <form method="POST" action="{{ url()->full() }}">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="password">Nuova password</label>
            <input type="password" class="form-control" id="password" name="password" required minlength="10" autocomplete="new-password" autofocus>
            <div class="form-text">{{ \App\Support\PasswordPolicy::hint() }}</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password_confirmation">Conferma password</label>
            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required minlength="10" autocomplete="new-password">
        </div>
        <button type="submit" class="btn-login">Imposta password e attiva l'account</button>
    </form>
@endsection
