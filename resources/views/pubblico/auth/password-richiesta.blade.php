@extends('pubblico.layouts.app')

@section('title', 'Password dimenticata — '.$associazione->nome)

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <h1 class="h4 mb-2">Password dimenticata?</h1>
                    <p class="text-muted small mb-4">
                        Inserisci l'email con cui ti sei registrato: se l'indirizzo è registrato,
                        riceverai un link per reimpostare la password.
                    </p>

                    <form method="POST" action="{{ route('pubblico.password.email', $associazione->slug) }}" novalidate>
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

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-envelope me-1"></i> Invia link di reimpostazione
                        </button>
                    </form>

                    <hr>

                    <p class="small mb-0">
                        <a href="{{ route('pubblico.login', $associazione->slug) }}">Torna al login</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
