@extends('layouts.app')

@section('title', 'Aggiornamenti - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-end pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-arrow-repeat me-2"></i>Aggiornamenti</h1>
            <p class="text-muted mb-0">Gestione degli aggiornamenti della piattaforma (solo super admin).</p>
        </div>
    </div>

    @if ($errors->has('update'))
        <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>{{ $errors->first('update') }}</div>
    @endif

    {{-- Stato versione --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-auto">
                    <div class="metric-chip" style="width:48px;height:48px;font-size:1.4rem;">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
                <div class="col">
                    <div class="text-muted small text-uppercase">Versione installata</div>
                    <div class="fs-3 fw-semibold">v{{ $stato['corrente'] }}</div>
                </div>

                @if ($stato['errore'])
                    <div class="col-12 col-md-auto ms-md-auto">
                        <span class="badge bg-secondary"><i class="bi bi-wifi-off me-1"></i>{{ $stato['errore'] }}</span>
                    </div>
                @elseif ($stato['disponibile'])
                    <div class="col-12 col-md-auto ms-md-auto text-md-end">
                        <span class="badge bg-success mb-2"><i class="bi bi-stars me-1"></i>Aggiornamento disponibile: v{{ $stato['ultima'] }}</span>
                        @if ($selfUpdate)
                            <form method="POST" action="{{ route('aggiornamenti.applica') }}"
                                  onsubmit="return confirm('Avviare l\'aggiornamento alla versione {{ $stato['ultima'] }}? Verrà creato un backup del database e l\'app andrà brevemente in manutenzione.');">
                                @csrf
                                <input type="hidden" name="versione" value="{{ $stato['ultima'] }}">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-download me-1"></i>Aggiorna alla v{{ $stato['ultima'] }}
                                </button>
                            </form>
                        @else
                            <div class="text-muted small">Auto-aggiornamento disabilitato: aggiorna manualmente da riga di comando.</div>
                        @endif
                    </div>
                @else
                    <div class="col-12 col-md-auto ms-md-auto">
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sei alla versione più recente</span>
                    </div>
                @endif
            </div>

            @if ($stato['disponibile'] && filled($stato['note']))
                <hr>
                <div class="fw-semibold mb-2">Novità della v{{ $stato['ultima'] }}</div>
                <div class="small text-muted" style="white-space: pre-wrap;">{{ \Illuminate\Support\Str::limit($stato['note'], 1500) }}</div>
                @if ($stato['url'])
                    <a href="{{ $stato['url'] }}" target="_blank" rel="noopener" class="small">Note di rilascio complete &rsaquo;</a>
                @endif
            @endif
        </div>
    </div>

    <div class="row g-4">
        {{-- Requisiti --}}
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-clipboard-check me-1"></i>Requisiti di sistema</div>
                <ul class="list-group list-group-flush">
                    @foreach ($requisiti as $nome => $ok)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            {{ $nome }}
                            <i class="bi {{ $ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' }}"></i>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- Storico --}}
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Storico aggiornamenti</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr><th>Data</th><th>Da → A</th><th>Esito</th><th>Eseguito da</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($storico as $log)
                                <tr>
                                    <td class="small text-nowrap">{{ $log->avviato_at?->format('d/m/Y H:i') }}</td>
                                    <td class="small">v{{ $log->from_version }} &rarr; v{{ $log->to_version }}</td>
                                    <td>
                                        @php
                                            $badge = match ($log->stato) {
                                                'completato' => 'bg-success',
                                                'fallito' => 'bg-danger',
                                                'rollback' => 'bg-warning text-dark',
                                                default => 'bg-secondary',
                                            };
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst($log->stato) }}</span>
                                    </td>
                                    <td class="small">{{ $log->esecutore?->nome_completo ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">Nessun aggiornamento registrato.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
