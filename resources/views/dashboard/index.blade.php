@extends('layouts.app')

@section('title', 'Dashboard - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2">Dashboard</h1>
            <p class="text-muted mb-0">
                @if ($vistaGlobale)
                    Benvenuto Super Admin. Panoramica globale di tutte le associazioni.
                @else
                    Panoramica generale di {{ session('associazione_nome', 'Manager') }}
                @endif
            </p>
        </div>
        <div class="text-muted small">
            <i class="bi bi-clock me-1"></i>Ultimo aggiornamento: {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    @if ($utente->isSuperAdmin())
        <div class="card mb-4">
            <div class="card-header bg-white">
                <i class="bi bi-buildings me-2"></i>Associazioni
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($associazioni as $associazione)
                        <div class="col-12 col-md-4 col-lg-3">
                            <div class="card h-100 {{ session('associazione_id') === $associazione->id ? 'border-primary' : '' }}">
                                <div class="card-body d-flex flex-column">
                                    <h6 class="card-title mb-3"><i class="bi bi-building me-1"></i>{{ $associazione->nome }}</h6>
                                    <form method="POST" action="{{ route('tenant.switch') }}" class="mt-auto">
                                        @csrf
                                        <input type="hidden" name="associazione_id" value="{{ $associazione->id }}">
                                        <button type="submit" class="btn btn-sm {{ session('associazione_id') === $associazione->id ? 'btn-primary' : 'btn-outline-primary' }} w-100">
                                            <i class="bi bi-box-arrow-in-right me-1"></i>Entra
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                    <div class="col-12 col-md-4 col-lg-3">
                        <div class="card h-100 bg-light">
                            <div class="card-body d-flex flex-column">
                                <h6 class="card-title mb-3"><i class="bi bi-globe2 me-1"></i>Tutte le associazioni</h6>
                                <form method="POST" action="{{ route('tenant.switch') }}" class="mt-auto">
                                    @csrf
                                    <input type="hidden" name="associazione_id" value="">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                        <i class="bi bi-eye me-1"></i>Vista Globale
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-primary"><i class="bi bi-people fs-2"></i></div>
                    <div>
                        <div class="fs-3 fw-bold">{{ $stats['total_soci'] }}</div>
                        <div class="text-muted small">Totale Soci</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-success"><i class="bi bi-person-check fs-2"></i></div>
                    <div>
                        <div class="fs-3 fw-bold">{{ $stats['soci_attivi'] }}</div>
                        <div class="text-muted small">Soci Attivi</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-warning"><i class="bi bi-exclamation-triangle fs-2"></i></div>
                    <div>
                        <div class="fs-3 fw-bold">{{ $stats['scadenze_imminenti'] }}</div>
                        <div class="text-muted small">Scadenze Imminenti (30 gg)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-info"><i class="bi bi-calendar-event fs-2"></i></div>
                    <div>
                        <div class="fs-3 fw-bold">{{ $stats['eventi_mese'] }}</div>
                        <div class="text-muted small">Eventi questo mese</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="color: #FF7B11;"><i class="bi bi-credit-card fs-2"></i></div>
                    <div>
                        <div class="fs-3 fw-bold">{{ $stats['quote_pagate'] }}</div>
                        <div class="text-muted small">Quote pagate {{ now()->year }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <i class="bi bi-calendar-event me-2"></i>Prossimi Eventi
                </div>
                <div class="card-body">
                    @forelse ($prossimiEventi as $evento)
                        <div class="d-flex justify-content-between align-items-center py-2 {{ $loop->last ? '' : 'border-bottom' }}">
                            <span>{{ $evento->titolo }}</span>
                            <span class="badge bg-primary">{{ $evento->data_evento?->format('d/m/Y') }}</span>
                        </div>
                    @empty
                        <p class="text-muted mb-0">Nessun evento in programma</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
