@extends('installer.layout')

@section('title', 'Database')
@section('step-label', '2 di 5')

@section('content')
    <h2>Configurazione database</h2>
    <p class="subtitle">Credenziali MySQL. Verranno salvate nel file <code>.env</code>, poi lo schema sarà creato automaticamente.</p>

    @if ($dbReady)
        <div class="alert alert-success mb-3">
            Il database risulta già configurato e migrato. Puoi proseguire.
        </div>
        <a href="{{ route('installer.admin') }}" class="btn btn-primary w-100 mb-2">
            Continua<i class="bi bi-arrow-right ms-2"></i>
        </a>
    @endif

    <form method="POST" action="{{ route('installer.database.store') }}">
        @csrf
        <div class="row g-3">
            <div class="col-8">
                <label class="form-label" for="host">Host</label>
                <input type="text" class="form-control" id="host" name="host" value="{{ old('host', '127.0.0.1') }}" required>
            </div>
            <div class="col-4">
                <label class="form-label" for="port">Porta</label>
                <input type="number" class="form-control" id="port" name="port" value="{{ old('port', 3306) }}" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="database">Nome database</label>
                <input type="text" class="form-control" id="database" name="database" value="{{ old('database', 'gestione_associazioni') }}" required>
            </div>
            <div class="col-6">
                <label class="form-label" for="username">Utente</label>
                <input type="text" class="form-control" id="username" name="username" value="{{ old('username') }}" required autocomplete="off">
            </div>
            <div class="col-6">
                <label class="form-label" for="password">Password</label>
                <input type="password" class="form-control" id="password" name="password" autocomplete="new-password">
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="crea_database" name="crea_database" value="1" @checked(old('crea_database'))>
                    <label class="form-check-label" for="crea_database">Crea il database se non esiste</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="consenti_non_vuoto" name="consenti_non_vuoto" value="1" @checked(old('consenti_non_vuoto'))>
                    <label class="form-check-label" for="consenti_non_vuoto">Consenti database non vuoto <span class="text-muted">(sconsigliato)</span></label>
                </div>
            </div>
        </div>

        <div id="testEsito" class="alert d-none mt-3 mb-0" role="status"></div>

        <div class="d-flex gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary" id="testConnessione"
                    data-url="{{ route('installer.database.test') }}">
                <i class="bi bi-plug me-1"></i>Testa connessione
            </button>
            <button type="submit" class="btn btn-primary flex-fill">
                <i class="bi bi-database-check me-2"></i>Connetti e installa lo schema
            </button>
        </div>
    </form>

    <script src="{{ asset('assets/js/installer.js') }}"></script>
@endsection
