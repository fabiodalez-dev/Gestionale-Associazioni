@extends('layouts.app')

@section('title', 'Anteprima import - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-search me-2"></i>Anteprima import: {{ $nomeFile }}</h1>
        <a href="{{ route('export-import.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Annulla</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body">
                <div class="h3 mb-0 text-primary">{{ count($report['modifiche']) }}</div>
                <div class="text-muted small">Soci da aggiornare</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body">
                <div class="h3 mb-0 text-success">{{ $report['invariati'] }}</div>
                <div class="text-muted small">Righe già allineate</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body">
                <div class="h3 mb-0 text-warning">{{ count($report['non_trovati']) }}</div>
                <div class="text-muted small">Soci non trovati</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body">
                <div class="h3 mb-0 text-danger">{{ count($report['errori']) }}</div>
                <div class="text-muted small">Righe con errori</div>
            </div></div>
        </div>
    </div>

    @if ($report['modifiche'] !== [])
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-pencil-square me-2"></i>Differenze da applicare</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Riga</th><th>Socio</th><th>Match</th><th>Campo</th><th>Valore attuale</th><th>Nuovo valore</th></tr></thead>
                    <tbody>
                        @foreach ($report['modifiche'] as $modifica)
                            @foreach ($modifica['campi'] as $campo => $valori)
                                <tr>
                                    @if ($loop->first)
                                        <td rowspan="{{ count($modifica['campi']) }}">{{ $modifica['riga'] }}</td>
                                        <td rowspan="{{ count($modifica['campi']) }}">
                                            {{ $modifica['nome_completo'] }}
                                            <span class="text-muted small">(n. {{ $modifica['numero_socio'] }})</span>
                                        </td>
                                        <td rowspan="{{ count($modifica['campi']) }}"><span class="badge bg-secondary">{{ $modifica['match'] === 'numero_socio' ? 'Numero socio' : 'Email' }}</span></td>
                                    @endif
                                    <td>{{ $campiAggiornabili[$campo] ?? $campo }}</td>
                                    <td class="text-danger"><del>{{ $valori['da'] ?? '—' }}</del></td>
                                    <td class="text-success"><strong>{{ $valori['a'] }}</strong></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <form method="POST" action="{{ route('export-import.import.applica') }}" class="mb-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Applicare le modifiche a {{ count($report['modifiche']) }} soci?');">
                <i class="bi bi-check-lg me-1"></i>Applica {{ count($report['modifiche']) }} aggiornamenti
            </button>
            <a href="{{ route('export-import.index') }}" class="btn btn-outline-secondary ms-2">Annulla</a>
        </form>
    @else
        <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Nessuna modifica da applicare: i dati del file coincidono con l'anagrafica.</div>
        <a href="{{ route('export-import.index') }}" class="btn btn-outline-secondary mb-4">Torna a Export / Import</a>
    @endif

    @if ($report['non_trovati'] !== [])
        <div class="card mb-3">
            <div class="card-header text-warning"><i class="bi bi-question-circle me-2"></i>Righe non abbinate ad alcun socio</div>
            <ul class="list-group list-group-flush">
                @foreach ($report['non_trovati'] as $voce)
                    <li class="list-group-item">Riga {{ $voce['riga'] }}: nessun socio con numero/email <code>{{ $voce['identificativo'] }}</code></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($report['errori'] !== [])
        <div class="card mb-3">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Righe con errori (saranno saltate)</div>
            <ul class="list-group list-group-flush">
                @foreach ($report['errori'] as $voce)
                    <li class="list-group-item">Riga {{ $voce['riga'] }}: {{ implode(' ', $voce['errori']) }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
