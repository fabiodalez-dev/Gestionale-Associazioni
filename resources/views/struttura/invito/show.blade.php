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
        <input type="email" name="email" value="{{ $utente->email }}" autocomplete="username" hidden readonly>
        @include('formello.campi', ['form' => new \App\Forms\ImpostaPasswordForm($utente, ['dati_utente' => [$utente->email, $utente->nome, $utente->cognome]])])
        <button type="submit" class="btn-login">Imposta password e attiva l'account</button>
    </form>
@endsection
