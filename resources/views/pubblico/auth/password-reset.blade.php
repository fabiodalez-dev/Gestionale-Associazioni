@extends('pubblico.layouts.app')

@section('title', 'Reimposta password — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Reimposta la password</h1>

                    <form method="POST" action="{{ route('pubblico.password.aggiorna', $associazione->slug) }}" novalidate>
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" name="email" id="email" value="{{ old('email', $email) }}"
                                   class="form-control @error('email') is-invalid @enderror"
                                   required autocomplete="email">
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Nuova password</label>
                            <input type="password" name="password" id="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   required autocomplete="new-password" aria-describedby="passwordHelp">
                            <div id="passwordHelp" class="form-text">Almeno 10 caratteri, con lettere e numeri.</div>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Conferma nuova password</label>
                            <input type="password" name="password_confirmation" id="password_confirmation"
                                   class="form-control" required autocomplete="new-password">
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-key me-1"></i> Reimposta password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
