@extends('layouts.guest')

@section('title', 'Conferma password - Gestione Associazioni')
@section('brand-icon', 'bi-key-fill')
@section('heading', 'Conferma la tua password')
@section('subheading', 'Per continuare è necessario confermare la password')

@section('content')
    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" placeholder="La tua password" required autofocus autocomplete="current-password">
        </div>
        <button type="submit" class="btn-login">Conferma</button>
    </form>
@endsection

@section('footer')
    <a class="login-footer-link" href="{{ route('dashboard') }}">Annulla e torna alla dashboard</a>
@endsection
