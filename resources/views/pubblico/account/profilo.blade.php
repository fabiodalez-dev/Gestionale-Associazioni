@extends('pubblico.layouts.app')

@section('title', 'Profilo — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Il mio profilo</h1>

    @include('pubblico.account._nav')

    <div class="row g-4">
        {{-- Anagrafica --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-person me-1"></i>Anagrafica</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('pubblico.account.profilo.aggiorna', $associazione->slug) }}">
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="nome" class="form-label">Nome</label>
                            <input type="text" class="form-control @error('nome') is-invalid @enderror"
                                   id="nome" name="nome" value="{{ old('nome', $account->nome) }}" required maxlength="100">
                            @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="cognome" class="form-label">Cognome</label>
                            <input type="text" class="form-control @error('cognome') is-invalid @enderror"
                                   id="cognome" name="cognome" value="{{ old('cognome', $account->cognome) }}" required maxlength="100">
                            @error('cognome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="telefono" class="form-label">Telefono <span class="text-muted small">(facoltativo)</span></label>
                            <input type="tel" class="form-control @error('telefono') is-invalid @enderror"
                                   id="telefono" name="telefono" value="{{ old('telefono', $account->telefono) }}" maxlength="30">
                            @error('telefono')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Salva anagrafica</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Cambio password --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-key me-1"></i>Cambio password</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('pubblico.account.profilo.password', $associazione->slug) }}">
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="password_corrente" class="form-label">Password corrente</label>
                            <input type="password" class="form-control @error('password_corrente') is-invalid @enderror"
                                   id="password_corrente" name="password_corrente" required autocomplete="current-password">
                            @error('password_corrente')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Nuova password</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password" required autocomplete="new-password"
                                   aria-describedby="passwordHelp">
                            <div id="passwordHelp" class="form-text">Minimo 10 caratteri, con lettere e numeri.</div>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Conferma nuova password</label>
                            <input type="password" class="form-control" id="password_confirmation"
                                   name="password_confirmation" required autocomplete="new-password">
                        </div>

                        <button type="submit" class="btn btn-primary">Aggiorna password</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Cambio email --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold"><i class="bi bi-envelope me-1"></i>Indirizzo email</div>
                <div class="card-body">
                    <p class="text-muted small">
                        Indirizzo attuale: <strong>{{ $account->email }}</strong>
                        @if ($account->hasVerifiedEmail())
                            <span class="badge text-bg-success ms-1">Verificato</span>
                        @endif
                    </p>
                    <form method="POST" action="{{ route('pubblico.account.profilo.email', $associazione->slug) }}" class="row g-2 align-items-start">
                        @csrf
                        @method('PATCH')

                        <div class="col-12 col-md-6">
                            <label for="email" class="form-label">Nuova email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email" value="{{ old('email') }}" required maxlength="255">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                Cambiando email dovrai verificare di nuovo l'indirizzo: ti invieremo un link di conferma.
                            </div>
                        </div>
                        <div class="col-12 col-md-auto pt-md-4 mt-md-2">
                            <button type="submit" class="btn btn-outline-primary">Cambia email</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
