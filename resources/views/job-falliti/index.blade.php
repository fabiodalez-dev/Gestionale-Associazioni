@extends('layouts.app')

@section('title', 'Job falliti - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-exclamation-octagon me-2"></i>Code e job falliti</h1>
            <p class="text-muted mb-0">Job in coda falliti dopo tutti i tentativi (email, export, promemoria…).</p>
        </div>
        @if ($job->total() > 0)
            <div class="d-flex gap-2">
                <form method="POST" action="{{ route('job-falliti.retry-all') }}" onsubmit="return confirm('Rimettere in coda tutti i job falliti?')">
                    @csrf
                    <button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Riprova tutti</button>
                </form>
                <form method="POST" action="{{ route('job-falliti.destroy-all') }}" onsubmit="return confirm('Eliminare definitivamente tutti i job falliti?')">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Elimina tutti</button>
                </form>
            </div>
        @endif
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Falliti nelle ultime 24 ore</div>
                <div class="fs-3 fw-semibold {{ $ultime24h > 0 ? 'text-danger' : '' }}">{{ $ultime24h }}</div>
            </div></div>
        </div>
        <div class="col-sm-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Job in attesa</div>
                <div class="fs-3 fw-semibold">{{ $inAttesa ?? '—' }}</div>
                @if ($inAttesa === null)<div class="small text-muted">Disponibile con la coda su database.</div>@endif
            </div></div>
        </div>
        <div class="col-sm-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Avvisi email</div>
                @if (config('failed-job-monitor.enabled') && $destinatari !== [])
                    <div class="small">{{ implode(', ', $destinatari) }}</div>
                @else
                    <div class="small text-warning-emphasis">Nessun destinatario: nessun avviso viene inviato.</div>
                @endif
                <a class="small" href="{{ route('impostazioni-sistema.index', ['sezione' => 'backup']) }}">Configura</a>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">{{ $job->total() }} job falliti</span>
            @if ($code->count() > 1)
                <form method="GET" class="d-flex gap-2">
                    <select name="coda" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtra per coda">
                        <option value="">Tutte le code</option>
                        @foreach ($code as $c)
                            <option value="{{ $c }}" @selected($coda === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>
        @if ($job->isEmpty())
            <div class="card-body text-center text-muted py-5"><i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>Nessun job fallito.</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Job</th><th>Coda</th><th>Errore</th><th>Fallito</th><th class="text-end">Azioni</th></tr></thead>
                    <tbody>
                        @foreach ($job as $j)
                            <tr>
                                <td><a href="{{ route('job-falliti.show', $j['uuid']) }}" class="fw-medium">{{ class_basename($j['nome']) }}</a><div class="small text-muted text-break">{{ $j['nome'] }}</div></td>
                                <td><span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $j['coda'] }}</span></td>
                                <td class="small text-break" style="max-width: 28rem;">{{ \Illuminate\Support\Str::limit($j['messaggio'], 160) }}</td>
                                <td class="small text-nowrap" title="{{ $j['fallito_at']->format('d/m/Y H:i:s') }}">{{ $j['fallito_at']->diffForHumans() }}</td>
                                <td class="text-end text-nowrap">
                                    <form method="POST" action="{{ route('job-falliti.retry', $j['uuid']) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary" title="Riprova"><i class="bi bi-arrow-repeat"></i><span class="visually-hidden">Riprova</span></button>
                                    </form>
                                    <form method="POST" action="{{ route('job-falliti.destroy', $j['uuid']) }}" class="d-inline" onsubmit="return confirm('Eliminare questo job fallito?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i><span class="visually-hidden">Elimina</span></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $job->links() }}</div>
        @endif
    </div>
@endsection
