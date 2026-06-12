@extends('layouts.app')

@section('title', 'Export / Import pro - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-arrow-down-up me-2"></i>Export / Import pro</h1>
        <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Anagrafica soci</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="row g-4">
        {{-- Export multi-foglio --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-file-earmark-excel me-2"></i>Export Excel multi-foglio</div>
                <div class="card-body">
                    <p class="text-muted">Un unico file Excel con quattro fogli: <strong>Soci</strong> (anagrafica completa),
                        <strong>Tessere {{ $anno }}</strong>, <strong>Quote {{ $anno }}</strong> e <strong>Libro soci</strong>
                        (registro cronologico con numero progressivo di iscrizione).</p>
                    <form method="GET" action="{{ route('export-import.export') }}" class="row g-2 align-items-end">
                        <div class="col-auto">
                            <label class="form-label" for="anno">Anno tessere/quote</label>
                            <input type="number" class="form-control" id="anno" name="anno" min="2000" max="2100" value="{{ $anno }}">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-download me-1"></i>Scarica Excel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Import di aggiornamento --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-file-earmark-arrow-up me-2"></i>Import di aggiornamento soci</div>
                <div class="card-body">
                    <p class="text-muted">Carica un Excel/CSV con intestazioni: ogni riga viene abbinata a un socio esistente per
                        <strong>numero socio</strong> o <strong>email</strong>. Prima di applicare vedrai l'<strong>anteprima
                        delle differenze</strong> campo per campo. Le celle vuote non modificano nulla.</p>
                    <p class="small text-muted mb-2">Campi aggiornabili:
                        @foreach ($campiAggiornabili as $chiave => $label)<code>{{ $chiave }}</code>@if (! $loop->last), @endif @endforeach
                    </p>
                    <form method="POST" action="{{ route('export-import.import.anteprima') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-8">
                            <label class="form-label" for="file">File Excel/CSV *</label>
                            <input type="file" class="form-control" id="file" name="file" accept=".xlsx,.xls,.csv,.txt" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Anteprima diff</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
