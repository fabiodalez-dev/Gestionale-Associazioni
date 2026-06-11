@extends('layouts.app')

@section('title', 'Profilo - '.session('app_name', config('app.name')))

@section('content')
    <div class="pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Il mio profilo</h1>
        <p class="text-muted mb-0">{{ $utente->nome_completo }} &middot; {{ $utente->email }} &middot; <span class="badge bg-secondary">{{ $utente->ruolo }}</span></p>
    </div>

    @php
        $statusMessages = [
            'two-factor-authentication-enabled' => 'Autenticazione a due fattori avviata: scansiona il QR code e conferma con un codice.',
            'two-factor-authentication-confirmed' => 'Autenticazione a due fattori attivata e confermata.',
            'two-factor-authentication-disabled' => 'Autenticazione a due fattori disattivata.',
            'recovery-codes-generated' => 'Nuovi codici di recupero generati.',
            'password-updated' => 'Password aggiornata con successo.',
        ];
        $status = session('status');
    @endphp
    @if ($status && isset($statusMessages[$status]))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ $statusMessages[$status] }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    @endif

    <div class="row g-4">
        {{-- Cambio password --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <i class="bi bi-key me-2"></i>Cambio password
                </div>
                <div class="card-body">
                    @if ($errors->updatePassword->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->updatePassword->all() as $errore)
                                    <li>{{ $errore }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('user-password.update') }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Password attuale</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Nuova password</label>
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" minlength="8">
                            <div class="form-text">Minimo 8 caratteri.</div>
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Conferma nuova password</label>
                            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Aggiorna password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Autenticazione a due fattori --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-shield-lock me-2"></i>Autenticazione a due fattori (2FA)</span>
                    @if ($twoFactorEnabled)
                        <span class="badge bg-success">Attiva</span>
                    @elseif ($twoFactorPending)
                        <span class="badge bg-warning text-dark">Da confermare</span>
                    @else
                        <span class="badge bg-secondary">Non attiva</span>
                    @endif
                </div>
                <div class="card-body">
                    @if ($errors->confirmTwoFactorAuthentication?->any())
                        <div class="alert alert-danger">
                            {{ $errors->confirmTwoFactorAuthentication->first() }}
                        </div>
                    @endif

                    @if (! $twoFactorEnabled && ! $twoFactorPending)
                        <p class="text-muted">Aggiungi un livello di sicurezza extra al tuo account: ad ogni accesso ti verrà chiesto un codice temporaneo generato da un'app di autenticazione (Google Authenticator, Aegis, 1Password, ...).</p>
                        <form method="POST" action="{{ route('two-factor.enable') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-shield-plus me-1"></i>Attiva 2FA
                            </button>
                        </form>
                    @elseif ($twoFactorPending)
                        <p class="text-muted">Scansiona questo QR code con la tua app di autenticazione e inserisci il codice generato per confermare l'attivazione.</p>
                        <div class="text-center mb-3 p-3 bg-white border rounded">
                            {!! $twoFactorQrCodeSvg !!}
                        </div>
                        <p class="small text-muted">Oppure inserisci manualmente la chiave: <code>{{ $twoFactorSetupKey }}</code></p>
                        <form method="POST" action="{{ route('two-factor.confirm') }}" class="mb-3">
                            @csrf
                            <div class="mb-3">
                                <label for="code" class="form-label">Codice di conferma</label>
                                <input type="text" class="form-control" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456" required autocomplete="one-time-code">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Conferma e attiva
                            </button>
                        </form>
                        <form method="POST" action="{{ route('two-factor.disable') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Annulla attivazione</button>
                        </form>
                    @else
                        <p class="text-muted">L'autenticazione a due fattori è attiva sul tuo account.</p>

                        <h6 class="mt-3">Codici di recupero</h6>
                        <p class="small text-muted">Conserva questi codici in un luogo sicuro: ti permettono di accedere se perdi il dispositivo di autenticazione. Ogni codice è monouso.</p>
                        <div class="bg-light border rounded p-3 mb-3 font-monospace small">
                            @foreach ($recoveryCodes as $codice)
                                <div>{{ $codice }}</div>
                            @endforeach
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i>Rigenera codici di recupero
                                </button>
                            </form>
                            <form method="POST" action="{{ route('two-factor.disable') }}" onsubmit="return confirm('Disattivare l\'autenticazione a due fattori?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-shield-x me-1"></i>Disattiva 2FA
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
