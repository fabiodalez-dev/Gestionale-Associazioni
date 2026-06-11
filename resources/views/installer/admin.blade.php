@extends('installer.layout')

@section('title', 'Amministratore')
@section('step-label', '3 di 4')

@section('content')
    <h2>Super amministratore</h2>
    <p class="subtitle">Crea la prima associazione e l'account che governa la piattaforma.</p>

    <form method="POST" action="{{ route('installer.admin.store') }}">
        @csrf
        <h6 class="text-uppercase text-muted mb-2" style="font-size: 0.71875rem; letter-spacing: 0.05em;">Associazione</h6>
        <div class="row g-3 mb-4">
            <div class="col-6">
                <label class="form-label" for="associazione_nome">Nome associazione</label>
                <input type="text" class="form-control" id="associazione_nome" name="associazione_nome" value="{{ old('associazione_nome') }}" required>
            </div>
            <div class="col-6">
                <label class="form-label" for="associazione_email">Email associazione</label>
                <input type="email" class="form-control" id="associazione_email" name="associazione_email" value="{{ old('associazione_email') }}" required>
            </div>
        </div>

        <h6 class="text-uppercase text-muted mb-2" style="font-size: 0.71875rem; letter-spacing: 0.05em;">Account super admin</h6>
        <div class="row g-3">
            <div class="col-6">
                <label class="form-label" for="nome">Nome</label>
                <input type="text" class="form-control" id="nome" name="nome" value="{{ old('nome') }}" required>
            </div>
            <div class="col-6">
                <label class="form-label" for="cognome">Cognome</label>
                <input type="text" class="form-control" id="cognome" name="cognome" value="{{ old('cognome') }}" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="email">Email di accesso</label>
                <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </div>
            <div class="col-6">
                <label class="form-label" for="password">Password</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password">
                <div class="form-text">Minimo 10 caratteri, con lettere e numeri.</div>
            </div>
            <div class="col-6">
                <label class="form-label" for="password_confirmation">Conferma password</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-4">
            <i class="bi bi-person-plus me-2"></i>Crea account e completa
        </button>
    </form>
@endsection
