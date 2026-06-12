@extends('layouts.app')

@section('title', 'Presenze - '.session('app_name', config('app.name')))

@section('content')
    @php
        $arrivati = $prenotazioni->filter(fn ($p) => $p->checkin_at !== null);
        $daRegistrare = $prenotazioni->filter(fn ($p) => $p->checkin_at === null);
    @endphp

    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-clipboard-check me-2"></i>Presenze</h1>
            <p class="text-muted mb-0">{{ $evento->titolo }} — {{ $evento->data_evento->format('d/m/Y H:i') }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('eventi.presenze.foglio', $evento) }}" class="btn btn-outline-primary" target="_blank" rel="noopener">
                <i class="bi bi-printer me-1"></i>Foglio presenze (PDF)
            </a>
            <a href="{{ route('eventi.prenotazioni.index', $evento) }}" class="btn btn-outline-secondary">
                <i class="bi bi-ticket-perforated me-1"></i>Prenotazioni
            </a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h4 mb-0">{{ $prenotazioni->count() }}</div>
                <div class="small text-muted">Prenotazioni confermate</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h4 mb-0 text-success">{{ $arrivati->count() }}</div>
                <div class="small text-muted">Check-in effettuati</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h4 mb-0">{{ $daRegistrare->count() }}</div>
                <div class="small text-muted">In attesa di arrivo</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center"><div class="card-body py-3">
                <div class="h4 mb-0">{{ $prenotazioni->sum('posti') }}</div>
                <div class="small text-muted">Posti totali</div>
            </div></div>
        </div>
    </div>

    <form method="POST" action="{{ route('eventi.presenze.store', $evento) }}" class="card">
        @csrf
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Registrazione manuale (oltre al check-in QR)</span>
            <button type="submit" class="btn btn-primary btn-sm" {{ $daRegistrare->isEmpty() ? 'disabled' : '' }}>
                <i class="bi bi-check2-all me-1"></i>Registra presenze selezionate
            </button>
        </div>
        @error('prenotazioni')
            <div class="alert alert-danger rounded-0 mb-0">{{ $message }}</div>
        @enderror
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" class="form-check-input" id="seleziona-tutti"
                                       onclick="document.querySelectorAll('input[name=\'prenotazioni[]\']:not(:disabled)').forEach(c => c.checked = this.checked)">
                            </th>
                            <th>Partecipante</th>
                            <th>Codice</th>
                            <th class="text-center">Posti</th>
                            <th class="text-center">Presenza</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($prenotazioni as $prenotazione)
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input" name="prenotazioni[]"
                                           value="{{ $prenotazione->id }}" {{ $prenotazione->checkin_at !== null ? 'disabled' : '' }}>
                                </td>
                                <td>
                                    <span class="fw-semibold">{{ $prenotazione->account?->nome_completo ?: 'Account rimosso' }}</span>
                                    @if ($prenotazione->account?->email)
                                        <div class="text-muted small">{{ $prenotazione->account->email }}</div>
                                    @endif
                                </td>
                                <td><code>{{ $prenotazione->codice }}</code></td>
                                <td class="text-center">{{ $prenotazione->posti }}</td>
                                <td class="text-center">
                                    @if ($prenotazione->checkin_at !== null)
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-lg me-1"></i>{{ $prenotazione->checkin_at->format('d/m/Y H:i') }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">Non registrata</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-people d-block mb-2" style="font-size: 2rem;"></i>
                                    Nessuna prenotazione confermata per questo evento.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>
@endsection
