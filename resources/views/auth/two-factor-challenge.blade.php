@extends('layouts.guest')

@section('title', 'Verifica in due passaggi - Gestione Associazioni')
@section('brand-icon', 'bi-shield-lock-fill')
@section('heading', 'Verifica in due passaggi')
@section('subheading', 'Inserisci il codice generato dalla tua app di autenticazione')

@section('content')
    <form method="POST" action="{{ route('two-factor.login') }}" id="codeForm">
        @csrf
        <div class="mb-3">
            <label for="code" class="form-label">Codice di autenticazione</label>
            <input type="text" class="form-control" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456" autofocus autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn-login">Verifica</button>
    </form>

    <hr class="my-4">

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf
        <div class="mb-3">
            <label for="recovery_code" class="form-label">Oppure usa un codice di recupero</label>
            <input type="text" class="form-control" id="recovery_code" name="recovery_code" placeholder="codice-di-recupero" autocomplete="off">
        </div>
        <button type="submit" class="btn btn-outline-secondary w-100 btn-sm">Usa codice di recupero</button>
    </form>
@endsection

@section('footer')
    <a class="login-footer-link" href="{{ route('login') }}">Torna al login</a>
@endsection
