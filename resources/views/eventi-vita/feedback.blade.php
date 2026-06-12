@extends('layouts.app')

@section('title', 'Feedback evento - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-star-half me-2"></i>Feedback post-evento</h1>
            <p class="text-muted mb-0">{{ $evento->titolo }} — {{ $evento->data_evento->format('d/m/Y H:i') }}</p>
        </div>
        <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-list-ul me-1"></i>Elenco eventi
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h3 mb-0">
                    @if ($stats['media'] !== null)
                        {{ number_format($stats['media'], 2, ',', '.') }} <span class="text-warning"><i class="bi bi-star-fill"></i></span>
                    @else
                        —
                    @endif
                </div>
                <div class="small text-muted">Voto medio</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h3 mb-0">{{ $stats['totale_compilati'] }}</div>
                <div class="small text-muted">Risposte ricevute</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h3 mb-0">{{ $stats['totale_inviati'] }}</div>
                <div class="small text-muted">Richieste inviate</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h3 mb-0">
                    {{ $stats['totale_inviati'] > 0 ? (int) round($stats['totale_compilati'] * 100 / $stats['totale_inviati']) : 0 }}%
                </div>
                <div class="small text-muted">Tasso di risposta</div>
            </div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Distribuzione voti</div>
                <div class="card-body">
                    @foreach (array_reverse($stats['distribuzione'], true) as $voto => $conteggio)
                        <div class="d-flex align-items-center mb-2">
                            <div class="text-nowrap me-2" style="width: 56px;">{{ $voto }} <i class="bi bi-star-fill text-warning"></i></div>
                            <div class="progress flex-grow-1" style="height: 14px;">
                                <div class="progress-bar bg-warning"
                                     style="width: {{ $stats['totale_compilati'] > 0 ? (int) round($conteggio * 100 / $stats['totale_compilati']) : 0 }}%"></div>
                            </div>
                            <div class="ms-2 small text-muted" style="width: 30px;">{{ $conteggio }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Commenti ({{ $stats['commenti']->count() }})</div>
                <div class="card-body">
                    @forelse ($stats['commenti'] as $feedback)
                        <div class="border-bottom pb-2 mb-2">
                            <div class="small text-muted mb-1">
                                @for ($i = 1; $i <= \App\Models\FeedbackEvento::VOTO_MAX; $i++)
                                    <i class="bi {{ $i <= (int) $feedback->voto ? 'bi-star-fill text-warning' : 'bi-star text-muted' }}"></i>
                                @endfor
                                <span class="ms-2">{{ $feedback->compilato_at?->format('d/m/Y H:i') }}</span>
                            </div>
                            <div>{{ $feedback->commento }}</div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">Nessun commento ricevuto.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
