@extends('pubblico.layouts.app')

@section('title', 'Registrati — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-6">
            <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Crea il tuo account</h1>
                    <p class="text-muted small mb-4">
                        Con l'account di {{ $associazione->nome }} puoi prenotare gli eventi e gestire le tue prenotazioni.
                    </p>

                    <form method="POST" action="{{ route('pubblico.registrazione.store', $associazione->slug) }}" novalidate>
                        @csrf
                        <x-honeypot />

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="nome" class="form-label">Nome <span class="text-danger">*</span></label>
                                <input type="text" name="nome" id="nome" value="{{ old('nome') }}"
                                       class="form-control @error('nome') is-invalid @enderror" required autocomplete="given-name">
                                @error('nome')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="cognome" class="form-label">Cognome <span class="text-danger">*</span></label>
                                <input type="text" name="cognome" id="cognome" value="{{ old('cognome') }}"
                                       class="form-control @error('cognome') is-invalid @enderror" required autocomplete="family-name">
                                @error('cognome')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" id="email" value="{{ old('email') }}"
                                       class="form-control @error('email') is-invalid @enderror" required autocomplete="email">
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="telefono" class="form-label">Telefono</label>
                                <input type="tel" name="telefono" id="telefono" value="{{ old('telefono') }}"
                                       class="form-control @error('telefono') is-invalid @enderror" autocomplete="tel">
                                @error('telefono')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" id="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       required autocomplete="new-password"
                                       aria-describedby="passwordHelp">
                                <div id="passwordHelp" class="form-text">Almeno 10 caratteri, con lettere e numeri.</div>
                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_confirmation" class="form-label">Conferma password <span class="text-danger">*</span></label>
                                <input type="password" name="password_confirmation" id="password_confirmation"
                                       class="form-control" required autocomplete="new-password">
                            </div>
                            <div class="col-12">
                                <div class="form-check @error('privacy') is-invalid @enderror">
                                    <input type="checkbox" name="privacy" id="privacy" value="1"
                                           class="form-check-input @error('privacy') is-invalid @enderror"
                                           @checked(old('privacy')) required>
                                    <label for="privacy" class="form-check-label small">
                                        Ho letto e accetto
                                        l'<a href="{{ route('privacy-policy.show', $associazione->id) }}" target="_blank" rel="noopener">informativa sulla privacy</a>
                                        <span class="text-danger">*</span>
                                    </label>
                                </div>
                                @error('privacy')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-person-plus me-1"></i> Registrati
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr>

                    <p class="small mb-0">
                        Hai già un account?
                        <a href="{{ route('pubblico.login', $associazione->slug) }}">Accedi</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
