@extends('layouts.app')

@section('title', 'Coda Invio Email — '.session('associazione_nome'))

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-hourglass-split me-2"></i>Coda Invio Email</h1>
    <div class="d-flex gap-2">
        @can('process', \App\Models\EmailQueue::class)
        <button type="button" class="btn btn-primary" id="btnProcessQueue">
            <i class="bi bi-play-circle me-1"></i>Elabora Coda
        </button>
        @endcan
        @can('cancel', new \App\Models\EmailQueue)
        <form method="POST" action="{{ route('email-coda.cancel-pending') }}" onsubmit="return confirm('Annullare tutte le email pendenti?');">
            @csrf
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Annulla Pendenti</button>
        </form>
        @endcan
    </div>
</div>

<div id="processProgress" class="mb-3" style="display:none;">
    <div class="progress" role="progressbar">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%">Elaborazione in corso…</div>
    </div>
    <div class="small text-muted mt-1" id="processResult"></div>
</div>

<div class="row g-3 mb-4">
    @foreach ([
        ['In attesa', $stats['pending'], 'warning', 'bi-hourglass'],
        ['In invio', $stats['sending'], 'info', 'bi-arrow-repeat'],
        ['Fallite', $stats['failed'], 'danger', 'bi-x-octagon'],
        ['Inviate oggi', $stats['inviate_oggi'], 'success', 'bi-check-circle'],
    ] as [$label, $value, $color, $icon])
        <div class="col-6 col-md-3">
            <div class="card text-center h-100 border-{{ $color }}">
                <div class="card-body">
                    <div class="fs-3 fw-bold text-{{ $color }}"><i class="bi {{ $icon }} me-1"></i>{{ $value }}</div>
                    <div class="text-muted small">{{ $label }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="alert alert-light border d-flex flex-wrap gap-4 small">
    <span><i class="bi bi-cpu me-1"></i>Motore coda Laravel:</span>
    <span><strong>{{ $engine['jobs'] }}</strong> job in attesa</span>
    <span><strong>{{ $engine['failed_jobs'] }}</strong> job falliti</span>
    <span class="text-muted">L'elaborazione automatica gira ogni 5 minuti con retry e backoff esponenziale.</span>
</div>

<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Priorità</th>
                    <th>Destinatario</th>
                    <th>Oggetto</th>
                    <th>Stato</th>
                    <th class="text-center">Tentativi</th>
                    <th>Creata</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php
                        $badge = ['pending' => 'warning', 'sending' => 'info', 'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary'][$item->status] ?? 'secondary';
                        $statoLabel = ['pending' => 'In attesa', 'sending' => 'In invio', 'sent' => 'Inviata', 'failed' => 'Fallita', 'cancelled' => 'Annullata'][$item->status] ?? $item->status;
                    @endphp
                    <tr>
                        <td><span class="badge bg-light text-dark border">{{ $item->priority }}</span></td>
                        <td>
                            {{ $item->to_email }}
                            @if ($item->socio)
                                <div class="small text-muted">{{ $item->socio->nome }} {{ $item->socio->cognome }}</div>
                            @endif
                        </td>
                        <td>{{ \Illuminate\Support\Str::limit($item->subject, 50) }}</td>
                        <td>
                            <span class="badge bg-{{ $badge }} {{ $badge === 'warning' ? 'text-dark' : '' }}">{{ $statoLabel }}</span>
                            @if ($item->error_message)
                                <div class="small text-danger">{{ \Illuminate\Support\Str::limit($item->error_message, 60) }}</div>
                            @endif
                        </td>
                        <td class="text-center">{{ $item->attempts }}/{{ $item->max_attempts }}</td>
                        <td>{{ $item->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            @if ($item->status === \App\Models\EmailQueue::STATUS_PENDING)
                                @can('cancel', $item)
                                <form method="POST" action="{{ route('email-coda.cancel', $item) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Annulla"><i class="bi bi-x-lg"></i> Annulla</button>
                                </form>
                                @endcan
                            @elseif ($item->status === \App\Models\EmailQueue::STATUS_FAILED)
                                @can('retry', $item)
                                <form method="POST" action="{{ route('email-coda.retry', $item) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Riprova"><i class="bi bi-arrow-clockwise"></i> Riprova</button>
                                </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">La coda è vuota.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($items->hasPages())
        <div class="card-footer">{{ $items->links() }}</div>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.getElementById('btnProcessQueue')?.addEventListener('click', function () {
    const btn = this;
    const progress = document.getElementById('processProgress');
    const result = document.getElementById('processResult');
    btn.disabled = true;
    progress.style.display = '';
    result.textContent = '';

    fetch(@json(route('email-coda.process')), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            result.textContent = 'Inviate: ' + data.sent + ' — Fallite: ' + data.failed + ' — Rimanenti: ' + data.remaining + '. Ricarico…';
            setTimeout(() => window.location.reload(), 2000);
        } else {
            result.textContent = data.error || 'Errore durante l\'elaborazione.';
            btn.disabled = false;
        }
    })
    .catch(() => {
        result.textContent = 'Errore di rete.';
        btn.disabled = false;
    });
});
</script>
@endpush
