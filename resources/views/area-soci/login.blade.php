@extends('layouts.guest')

@section('title', 'Area Soci - Accedi')
@section('brand-icon', 'bi-person-badge')
@section('heading', 'Area Soci')
@section('subheading', 'Accedi alla tua area riservata')

@section('content')
    <ul class="nav nav-tabs mb-3" id="loginTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-login" data-bs-toggle="tab" data-bs-target="#pane-login" type="button" role="tab" aria-controls="pane-login" aria-selected="true">Accedi</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-reset" data-bs-toggle="tab" data-bs-target="#pane-reset" type="button" role="tab" aria-controls="pane-reset" aria-selected="false">Password dimenticata</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="pane-login" role="tabpanel" aria-labelledby="tab-login">
            <form method="POST" action="{{ route('area-soci.login.attempt') }}">
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="nome@esempio.it">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                </div>
                <button type="submit" class="btn-login">Accedi</button>
            </form>
        </div>

        <div class="tab-pane fade" id="pane-reset" role="tabpanel" aria-labelledby="tab-reset">
            <p class="text-muted small mb-3">
                Inserisci la tua email: se è registrata riceverai un link per impostare (o reimpostare) la password.
            </p>
            <form method="POST" action="{{ route('area-soci.password.email') }}">
                @csrf
                <div class="mb-3">
                    <label for="email_reset" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email_reset" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="nome@esempio.it">
                </div>
                <button type="submit" class="btn-login">Invia link</button>
            </form>
        </div>
    </div>
@endsection

@section('footer')
    <a href="{{ url('/login') }}" class="login-footer-link">Sei un amministratore? Accedi al pannello</a>
@endsection
