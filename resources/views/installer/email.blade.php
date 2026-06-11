@extends('installer.layout')

@section('title', 'Email')
@section('step-label', '4 di 5')

@section('content')
    <h2>Email di piattaforma <span class="text-muted" style="font-weight: 400;">(facoltativo)</span></h2>
    <p class="subtitle">
        Usata per notifiche di sistema (backup, health check, reset password admin).
        Le email ai soci usano gli SMTP per associazione, configurabili dopo da
        <em>Email &rsaquo; Impostazioni SMTP</em>. Puoi saltare questo passo.
    </p>

    <form method="POST" action="{{ route('installer.email.store') }}">
        @csrf
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="driver">Invio email</label>
                <select class="form-select" id="driver" name="driver">
                    <option value="log" @selected(old('driver', 'log') === 'log')>Solo log (configuro dopo)</option>
                    <option value="smtp" @selected(old('driver') === 'smtp')>SMTP</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label" for="from_address">Email mittente</label>
                <input type="email" class="form-control" id="from_address" name="from_address" value="{{ old('from_address') }}" placeholder="noreply@tuodominio.it">
            </div>
            <div class="col-6">
                <label class="form-label" for="from_name">Nome mittente</label>
                <input type="text" class="form-control" id="from_name" name="from_name" value="{{ old('from_name', 'Gestione Associazioni') }}">
            </div>
        </div>

        <div id="smtpFields" class="row g-3 mt-0 {{ old('driver') === 'smtp' ? '' : 'd-none' }}">
            <div class="col-8">
                <label class="form-label" for="smtp_host">Host SMTP</label>
                <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="{{ old('smtp_host') }}">
            </div>
            <div class="col-4">
                <label class="form-label" for="smtp_port">Porta</label>
                <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="{{ old('smtp_port', 587) }}">
            </div>
            <div class="col-6">
                <label class="form-label" for="smtp_username">Utente</label>
                <input type="text" class="form-control" id="smtp_username" name="smtp_username" value="{{ old('smtp_username') }}" autocomplete="off">
            </div>
            <div class="col-6">
                <label class="form-label" for="smtp_password">Password</label>
                <input type="password" class="form-control" id="smtp_password" name="smtp_password" autocomplete="new-password">
            </div>
            <div class="col-6">
                <label class="form-label" for="smtp_encryption">Cifratura</label>
                <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                    <option value="tls" @selected(old('smtp_encryption', 'tls') === 'tls')>TLS (STARTTLS, porta 587)</option>
                    <option value="smtps" @selected(old('smtp_encryption') === 'smtps')>SMTPS (porta 465)</option>
                </select>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <a href="{{ route('installer.done') }}" class="btn btn-outline-secondary">Salta</a>
            <button type="submit" class="btn btn-primary flex-fill">
                Salva e completa<i class="bi bi-arrow-right ms-2"></i>
            </button>
        </div>
    </form>

    <script src="{{ asset('assets/js/installer.js') }}"></script>
@endsection
