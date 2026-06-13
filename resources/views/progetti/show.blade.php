@extends('layouts.app')

@section('title', $progetto->nome)

@section('content')
@php
    $avanzamento = $progetto->percentualeAvanzamento();
    $badge = match ($progetto->stato) {
        \App\Models\Progetto::STATO_ATTIVO => 'bg-success',
        \App\Models\Progetto::STATO_CONCLUSO => 'bg-secondary',
        default => 'bg-warning text-dark',
    };
@endphp

<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <div>
        <h1 class="h2 mb-1"><i class="bi bi-kanban me-2"></i>{{ $progetto->nome }}</h1>
        <span class="badge {{ $badge }}">{{ ucfirst($progetto->stato) }}</span>
        @if ($progetto->dal || $progetto->al)
            <span class="text-muted small ms-2">
                {{ $progetto->dal?->format('d/m/Y') ?? '…' }} → {{ $progetto->al?->format('d/m/Y') ?? '…' }}
            </span>
        @endif
    </div>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('progetti.report-pdf', $progetto) }}" class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-pdf me-1"></i>Report PDF
        </a>
        @can('update', $progetto)
            <a href="{{ route('progetti.edit', $progetto) }}" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Modifica
            </a>
        @endcan
        @can('delete', $progetto)
            <form method="POST" action="{{ route('progetti.destroy', $progetto) }}"
                  onsubmit="return confirm('Eliminare questo progetto? I collegamenti a movimenti, eventi e turni verranno azzerati.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Elimina</button>
            </form>
        @endcan
    </div>
</div>

<div class="row g-3">
    {{-- Colonna sinistra: anagrafica + rendiconto + ore --}}
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3">Anagrafica</h2>
                @if (filled($progetto->descrizione))
                    <p>{{ $progetto->descrizione }}</p>
                @else
                    <p class="text-muted">Nessuna descrizione.</p>
                @endif
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">Responsabile</dt>
                    <dd class="col-sm-8">{{ $progetto->responsabile ? trim($progetto->responsabile->cognome.' '.$progetto->responsabile->nome) ?: $progetto->responsabile->email : '—' }}</dd>
                    <dt class="col-sm-4 text-muted">Budget</dt>
                    <dd class="col-sm-8">{{ $progetto->budget !== null ? '€ '.number_format((float) $progetto->budget, 2, ',', '.') : '—' }}</dd>
                </dl>
            </div>
        </div>

        {{-- f.13 — Rendiconto economico --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0"><i class="bi bi-cash-coin me-1"></i>Rendiconto economico</h2>
                    <a href="{{ route('prima-nota.index', ['progetto_id' => $progetto->id]) }}" class="btn btn-sm btn-outline-secondary">
                        Prima nota
                    </a>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="text-muted small">Entrate</div>
                        <div class="fw-semibold text-success">&euro; {{ number_format($rendiconto['entrate'], 2, ',', '.') }}</div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small">Uscite</div>
                        <div class="fw-semibold text-danger">&euro; {{ number_format($rendiconto['uscite'], 2, ',', '.') }}</div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small">Saldo</div>
                        <div class="fw-semibold {{ $rendiconto['saldo'] >= 0 ? 'text-success' : 'text-danger' }}">
                            &euro; {{ number_format($rendiconto['saldo'], 2, ',', '.') }}
                        </div>
                    </div>
                </div>
                @if ($rendiconto['budget'] !== null)
                    <div class="alert {{ ($rendiconto['scostamento'] ?? 0) >= 0 ? 'alert-success' : 'alert-danger' }} py-2 small mb-3">
                        Budget &euro; {{ number_format($rendiconto['budget'], 2, ',', '.') }} —
                        {{ ($rendiconto['scostamento'] ?? 0) >= 0 ? 'margine residuo' : 'sforamento' }}
                        &euro; {{ number_format(abs($rendiconto['scostamento'] ?? 0), 2, ',', '.') }}
                    </div>
                @endif
                @if ($rendiconto['perCategoria']->isNotEmpty())
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Categoria</th><th>Tipo</th><th class="text-end">Totale</th></tr></thead>
                        <tbody>
                        @foreach ($rendiconto['perCategoria'] as $riga)
                            <tr>
                                <td>{{ $riga['categoria'] }}</td>
                                <td><span class="badge {{ $riga['tipo'] === \App\Models\Movimento::TIPO_ENTRATA ? 'bg-success' : 'bg-danger' }}">{{ ucfirst($riga['tipo']) }}</span></td>
                                <td class="text-end">&euro; {{ number_format($riga['totale'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-muted small mb-0">Nessun movimento imputato al progetto.</p>
                @endif
            </div>
        </div>

        {{-- f.15 — Ore volontariato dai turni --}}
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3"><i class="bi bi-clock-history me-1"></i>Ore di volontariato</h2>
                <p class="mb-2">Totale: <span class="fw-semibold">{{ number_format($oreVolontariato['totale'], 2, ',', '.') }} ore</span></p>
                @if ($oreVolontariato['perSocio']->isNotEmpty())
                    <ul class="list-group list-group-flush small">
                        @foreach ($oreVolontariato['perSocio'] as $riga)
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span>{{ $riga['socio'] }}</span>
                                <span class="fw-semibold">{{ number_format($riga['ore'], 2, ',', '.') }} h</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted small mb-0">Nessuna ora registrata sui turni collegati.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Colonna destra: milestone + eventi + turni --}}
    <div class="col-lg-5">
        {{-- f.16 — Milestone --}}
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-2"><i class="bi bi-check2-square me-1"></i>Milestone</h2>
                <div class="progress mb-1" role="progressbar" aria-valuenow="{{ $avanzamento }}" aria-valuemin="0" aria-valuemax="100" style="height: 10px;">
                    <div class="progress-bar bg-primary" style="width: {{ $avanzamento }}%;"></div>
                </div>
                <p class="text-muted small mb-3">{{ $avanzamento }}% completato
                    ({{ $progetto->milestones->whereNotNull('completata_at')->count() }}/{{ $progetto->milestones->count() }})</p>

                <ul class="list-group list-group-flush mb-3">
                    @forelse ($progetto->milestones as $milestone)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2 flex-grow-1">
                                @can('update', $progetto)
                                    <form method="POST" action="{{ route('progetti.milestone.toggle', [$progetto, $milestone]) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent" title="{{ $milestone->isCompletata() ? 'Riapri' : 'Completa' }}">
                                            <i class="bi {{ $milestone->isCompletata() ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' }} fs-5"></i>
                                        </button>
                                    </form>
                                @else
                                    <i class="bi {{ $milestone->isCompletata() ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' }} fs-5"></i>
                                @endcan
                                <span class="{{ $milestone->isCompletata() ? 'text-decoration-line-through text-muted' : '' }}">
                                    {{ $milestone->titolo }}
                                    @if ($milestone->scadenza)
                                        <small class="d-block {{ $milestone->isScaduta() ? 'text-danger fw-semibold' : 'text-muted' }}">
                                            <i class="bi bi-calendar"></i> {{ $milestone->scadenza->format('d/m/Y') }}
                                            @if ($milestone->isScaduta()) — scaduta @endif
                                        </small>
                                    @endif
                                </span>
                            </div>
                            @can('update', $progetto)
                                <form method="POST" action="{{ route('progetti.milestone.destroy', [$progetto, $milestone]) }}"
                                      onsubmit="return confirm('Eliminare questa milestone?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-x-lg"></i></button>
                                </form>
                            @endcan
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-muted small">Nessuna milestone.</li>
                    @endforelse
                </ul>

                @can('update', $progetto)
                    <form method="POST" action="{{ route('progetti.milestone.store', $progetto) }}" class="row g-2">
                        @csrf
                        <div class="col-12 col-sm">
                            <input type="text" name="titolo" class="form-control form-control-sm" placeholder="Nuova milestone…" maxlength="200" required>
                        </div>
                        <div class="col-auto">
                            <input type="date" name="scadenza" class="form-control form-control-sm" title="Scadenza (opzionale)">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                @endcan
            </div>
        </div>

        {{-- f.14 — Eventi collegati --}}
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-2"><i class="bi bi-calendar-event me-1"></i>Eventi collegati ({{ $progetto->eventi->count() }})</h2>
                @forelse ($progetto->eventi as $evento)
                    <div class="d-flex justify-content-between border-bottom py-1 small">
                        <span>{{ $evento->titolo }}</span>
                        <span class="text-muted">{{ $evento->data_evento?->format('d/m/Y') }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">Nessun evento collegato.</p>
                @endforelse
            </div>
        </div>

        {{-- f.14 — Turni collegati --}}
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-2"><i class="bi bi-people me-1"></i>Turni collegati ({{ $progetto->turni->count() }})</h2>
                @forelse ($progetto->turni as $turno)
                    <div class="d-flex justify-content-between border-bottom py-1 small">
                        <span>{{ $turno->titolo }}</span>
                        <span class="text-muted">{{ $turno->data?->format('d/m/Y') }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">Nessun turno collegato.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
