@extends('layouts.guest')

@section('title', 'Recupero password - Gestione Associazioni')
@section('brand-icon', 'bi-key-fill')
@section('subheading', 'Recupera l\'accesso al pannello')

@section('content')
    @if (session('status'))
        <p class="small text-body-secondary mb-0">Controlla la tua casella di posta, anche nella cartella spam. Il link è valido per {{ config('auth.passwords.utenti.expire') }} minuti.</p>
    @else
        <p class="small text-body-secondary mb-3">Inserisci l'email del tuo account: ti invieremo un link per scegliere una nuova password.</p>
        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" placeholder="nome@associazione.it" required autofocus autocomplete="username">
            </div>
            <button type="submit" class="btn-login">Invia link di recupero</button>
        </form>
    @endif
@endsection

@section('footer')
    <a class="login-footer-link" href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Torna al login</a>
@endsection
