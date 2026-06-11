@extends('pubblico.layouts.app')

@section('title', 'Accedi — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Accedi</h1>

                    <form method="POST" action="{{ route('pubblico.login.attempt', $associazione->slug) }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" name="email" id="email" value="{{ old('email') }}"
                                   class="form-control @error('email') is-invalid @enderror"
                                   required autocomplete="email" autofocus>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   required autocomplete="current-password">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" name="remember" id="remember" value="1" class="form-check-input">
                            <label for="remember" class="form-check-label">Ricordami</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Accedi
                        </button>
                    </form>

                    <hr>

                    <p class="small mb-1">
                        <a href="{{ route('pubblico.password.richiesta', $associazione->slug) }}">Password dimenticata?</a>
                    </p>
                    <p class="small mb-0">
                        Non hai un account?
                        <a href="{{ route('pubblico.registrazione', $associazione->slug) }}">Registrati</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
