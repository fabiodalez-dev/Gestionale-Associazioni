@extends('layouts.app')

@section('title', 'Importa Soci - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-upload me-2"></i>Importa Soci da Excel/CSV</h1>
        <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <p class="text-muted">
                Carica un file <strong>.xlsx</strong>, <strong>.xls</strong> o <strong>.csv</strong> con riga di intestazione.
                Colonne riconosciute: <code>nome</code>*, <code>cognome</code>*, <code>email</code>*,
                <code>codice_fiscale</code>, <code>telefono</code>, <code>data_nascita</code>, <code>data_iscrizione</code>,
                <code>stato</code>, <code>numero_socio</code>, <code>indirizzo</code>, <code>citta</code>,
                <code>provincia</code>, <code>cap</code>, <code>note</code>. Le righe non valide vengono saltate e riportate sotto.
            </p>
            <form method="POST" action="{{ route('soci.import.store') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-6">
                    <label class="form-label" for="file">File *</label>
                    <input type="file" class="form-control" id="file" name="file" accept=".xlsx,.xls,.csv,.txt" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>Importa</button>
                </div>
            </form>
        </div>
    </div>

    @if (session('import_report'))
        @php $report = session('import_report'); @endphp
        <div class="card">
            <div class="card-header bg-white"><i class="bi bi-clipboard-data me-1"></i>Report import</div>
            <div class="card-body">
                <p>
                    <span class="badge bg-success">{{ $report['importati'] }} importati</span>
                    <span class="badge bg-{{ count($report['errori']) > 0 ? 'danger' : 'secondary' }}">{{ count($report['errori']) }} righe scartate</span>
                </p>
                @if (count($report['errori']) > 0)
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th style="width:100px">Riga</th><th>Errori</th></tr></thead>
                            <tbody>
                                @foreach ($report['errori'] as $errore)
                                    <tr>
                                        <td>{{ $errore['riga'] }}</td>
                                        <td>
                                            <ul class="mb-0 ps-3">
                                                @foreach ($errore['errori'] as $messaggio)
                                                    <li>{{ $messaggio }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection
