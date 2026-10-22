@extends('layouts.app')

@section('title', 'Job fallito - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 pb-3 mb-4 border-bottom">
        <div>
            <a href="{{ route('job-falliti.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Job falliti</a>
            <h1 class="h3 mb-0 mt-1 text-break">{{ class_basename($job['nome']) }}</h1>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('job-falliti.retry', $job['uuid']) }}">
                @csrf
                <button class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Riprova</button>
            </form>
            <form method="POST" action="{{ route('job-falliti.destroy', $job['uuid']) }}" onsubmit="return confirm('Eliminare questo job fallito?')">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Elimina</button>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <table class="table table-sm mb-0">
            <tbody>
                <tr><th class="ps-3 fw-medium" style="width: 12rem;">Classe</th><td class="text-break"><code>{{ $job['nome'] }}</code></td></tr>
                <tr><th class="ps-3 fw-medium">Coda</th><td>{{ $job['coda'] }} ({{ $job['connessione'] }})</td></tr>
                <tr><th class="ps-3 fw-medium">Tentativi</th><td>{{ $job['tentativi'] ?? '—' }}@if ($job['max_tentativi']) su {{ $job['max_tentativi'] }}@endif</td></tr>
                <tr><th class="ps-3 fw-medium">Fallito il</th><td>{{ $job['fallito_at']->format('d/m/Y H:i:s') }}</td></tr>
                <tr><th class="ps-3 fw-medium">UUID</th><td><code class="small">{{ $job['uuid'] }}</code></td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">Eccezione</div>
        <div class="card-body">
            <p class="fw-medium text-danger text-break">{{ $job['messaggio'] }}</p>
            <pre class="small bg-body-tertiary p-3 rounded mb-0" style="max-height: 32rem; overflow: auto; white-space: pre-wrap;">{{ $job['eccezione'] }}</pre>
        </div>
    </div>
@endsection
