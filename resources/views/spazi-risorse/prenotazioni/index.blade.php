@extends('layouts.app')

@section('title', 'Prenotazioni spazi - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-calendar-week me-2"></i>Prenotazioni spazi</h1>
            <p class="text-muted mb-0">Calendario settimanale e prenotazioni interne</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('spazi.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-door-open me-1"></i>Spazi
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#prenotazioneModal"
                    id="btnNuovaPrenotazione" @disabled($spazi->isEmpty())>
                <i class="bi bi-plus-lg me-1"></i>Nuova prenotazione
            </button>
        </div>
    </div>

    @if ($spazi->isEmpty())
        <div class="alert alert-info">
            Non ci sono spazi censiti. <a href="{{ route('spazi.index') }}">Crea uno spazio</a> per gestire le prenotazioni.
        </div>
    @else
        {{-- Selettore spazio + navigazione settimana --}}
        <form method="GET" action="{{ route('spazi.prenotazioni.index') }}" class="row g-2 mb-3 align-items-end">
            <div class="col-12 col-md-4">
                <label for="spazioSelect" class="form-label">Spazio</label>
                <select name="spazio" id="spazioSelect" class="form-select" onchange="this.form.submit()">
                    @foreach ($spazi as $sp)
                        <option value="{{ $sp->id }}" @selected($spazioSelezionato && $spazioSelezionato->id === $sp->id)>{{ $sp->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label for="settimanaInput" class="form-label">Settimana</label>
                <input type="date" name="settimana" id="settimanaInput" class="form-control"
                       value="{{ $inizioSettimana->format('Y-m-d') }}" onchange="this.form.submit()">
            </div>
            <div class="col-auto">
                @php
                    $settimanaPrec = $inizioSettimana->copy()->subWeek()->format('Y-m-d');
                    $settimanaSucc = $inizioSettimana->copy()->addWeek()->format('Y-m-d');
                    $spazioId = $spazioSelezionato?->id;
                @endphp
                <a class="btn btn-outline-secondary" href="{{ route('spazi.prenotazioni.index', ['spazio' => $spazioId, 'settimana' => $settimanaPrec]) }}"><i class="bi bi-chevron-left"></i></a>
                <a class="btn btn-outline-secondary" href="{{ route('spazi.prenotazioni.index', ['spazio' => $spazioId, 'settimana' => now()->format('Y-m-d')]) }}">Oggi</a>
                <a class="btn btn-outline-secondary" href="{{ route('spazi.prenotazioni.index', ['spazio' => $spazioId, 'settimana' => $settimanaSucc]) }}"><i class="bi bi-chevron-right"></i></a>
            </div>
        </form>

        {{-- Calendario settimanale per lo spazio selezionato --}}
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-grid-3x3 me-1"></i>
                {{ $spazioSelezionato?->nome }} — settimana dal {{ $inizioSettimana->format('d/m/Y') }} al {{ $fineSettimana->format('d/m/Y') }}
            </div>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 text-center align-top">
                    <thead>
                        <tr>
                            @foreach ($giorniSettimana as $giorno)
                                <th class="{{ $giorno->isToday() ? 'table-warning' : '' }}" style="width: 14.28%">
                                    {{ ucfirst($giorno->locale('it')->isoFormat('ddd')) }}<br>
                                    <span class="small text-muted">{{ $giorno->format('d/m') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            @foreach ($giorniSettimana as $giorno)
                                @php $delGiorno = $prenotazioniPerGiorno->get($giorno->format('Y-m-d'), collect()); @endphp
                                <td class="{{ $giorno->isToday() ? 'table-warning' : '' }}" style="min-height: 90px; height: 90px;">
                                    @forelse ($delGiorno as $p)
                                        <a href="{{ route('spazi.prenotazioni.index', ['spazio' => $spazioSelezionato->id, 'settimana' => $inizioSettimana->format('Y-m-d'), 'edit' => $p->id]) }}"
                                           class="d-block text-start text-decoration-none mb-1">
                                            <span class="badge bg-primary w-100 text-wrap text-start">
                                                {{ $p->dal->format('H:i') }}–{{ $p->al->format('H:i') }}<br>
                                                {{ \Illuminate\Support\Str::limit($p->titolo, 25) }}
                                            </span>
                                        </a>
                                    @empty
                                        <span class="text-muted small">&mdash;</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Lista prossime prenotazioni (tutti gli spazi) --}}
    <div class="card">
        <div class="card-header"><i class="bi bi-list-ul me-1"></i>Prossime prenotazioni</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Spazio</th><th>Titolo/uso</th><th>Dal</th><th>Al</th><th>Richiedente</th><th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prossime as $p)
                        <tr>
                            <td><strong>{{ $p->spazio?->nome ?: '-' }}</strong></td>
                            <td>{{ $p->titolo }}</td>
                            <td>{{ $p->dal->format('d/m/Y H:i') }}</td>
                            <td>{{ $p->al->format('d/m/Y H:i') }}</td>
                            <td>{{ $p->richiedente?->nome ?? '-' }}</td>
                            <td class="text-end">
                                @php
                                    $pJson = json_encode([
                                        'id' => $p->id,
                                        'spazio_id' => $p->spazio_id,
                                        'titolo' => $p->titolo,
                                        'dal' => $p->dal->format('Y-m-d\TH:i'),
                                        'al' => $p->al->format('Y-m-d\TH:i'),
                                        'note' => $p->note,
                                    ]);
                                @endphp
                                <button class="btn btn-sm btn-outline-primary btn-edit-prenotazione" type="button"
                                        data-bs-toggle="modal" data-bs-target="#prenotazioneModal"
                                        data-prenotazione="{{ $pJson }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="{{ route('spazi.prenotazioni.destroy', $p) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questa prenotazione?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Nessuna prenotazione in programma.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $prossime->links() }}</div>

    {{-- Modale crea/modifica prenotazione --}}
    <div class="modal fade" id="prenotazioneModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('spazi.prenotazioni.store') }}" id="prenotazioneForm">
                    @csrf
                    <input type="hidden" name="_method" id="prenotazione_method" value="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="prenotazioneModalTitolo">Nuova prenotazione</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="prenotazione_spazio_id" class="form-label">Spazio <span class="text-danger">*</span></label>
                            <select class="form-select @error('spazio_id') is-invalid @enderror" id="prenotazione_spazio_id" name="spazio_id" required>
                                @foreach ($spazi as $sp)
                                    <option value="{{ $sp->id }}"
                                        @selected(old('spazio_id', $spazioSelezionato?->id) === $sp->id)>{{ $sp->nome }}</option>
                                @endforeach
                            </select>
                            @error('spazio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="prenotazione_titolo" class="form-label">Titolo / uso <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="prenotazione_titolo"
                                   name="titolo" value="{{ old('titolo') }}" maxlength="200" required>
                            @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="prenotazione_dal" class="form-label">Dal <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control @error('dal') is-invalid @enderror" id="prenotazione_dal"
                                       name="dal" value="{{ old('dal') }}" required>
                                @error('dal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="prenotazione_al" class="form-label">Al <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control @error('al') is-invalid @enderror" id="prenotazione_al"
                                       name="al" value="{{ old('al') }}" required>
                                @error('al')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="prenotazione_note" class="form-label">Note</label>
                            <textarea class="form-control @error('note') is-invalid @enderror" id="prenotazione_note" name="note" rows="2">{{ old('note') }}</textarea>
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Salva</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('prenotazioneForm');
        const titolo = document.getElementById('prenotazioneModalTitolo');
        const metodo = document.getElementById('prenotazione_method');
        const azioneBase = "{{ route('spazi.prenotazioni.store') }}";
        const azioneBaseUpd = "{{ url('spazi/prenotazioni') }}";

        document.getElementById('btnNuovaPrenotazione')?.addEventListener('click', function () {
            form.action = azioneBase;
            metodo.value = 'POST';
            titolo.textContent = 'Nuova prenotazione';
            form.reset();
        });

        document.querySelectorAll('.btn-edit-prenotazione').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const p = JSON.parse(this.dataset.prenotazione);
                form.action = azioneBaseUpd + '/' + p.id;
                metodo.value = 'PUT';
                titolo.textContent = 'Modifica prenotazione';
                document.getElementById('prenotazione_spazio_id').value = p.spazio_id ?? '';
                document.getElementById('prenotazione_titolo').value = p.titolo ?? '';
                document.getElementById('prenotazione_dal').value = p.dal ?? '';
                document.getElementById('prenotazione_al').value = p.al ?? '';
                document.getElementById('prenotazione_note').value = p.note ?? '';
            });
        });

        @if ($editPrenotazione)
            @php
                $editPrenotazioneData = [
                    'id' => $editPrenotazione->id,
                    'spazio_id' => $editPrenotazione->spazio_id,
                    'titolo' => $editPrenotazione->titolo,
                    'dal' => $editPrenotazione->dal->format('Y-m-d\TH:i'),
                    'al' => $editPrenotazione->al->format('Y-m-d\TH:i'),
                    'note' => $editPrenotazione->note,
                ];
            @endphp
            (function () {
                const p = @json($editPrenotazioneData);
                form.action = azioneBaseUpd + '/' + p.id;
                metodo.value = 'PUT';
                titolo.textContent = 'Modifica prenotazione';
                document.getElementById('prenotazione_spazio_id').value = p.spazio_id;
                document.getElementById('prenotazione_titolo').value = p.titolo;
                document.getElementById('prenotazione_dal').value = p.dal;
                document.getElementById('prenotazione_al').value = p.al;
                document.getElementById('prenotazione_note').value = p.note ?? '';
                new bootstrap.Modal(document.getElementById('prenotazioneModal')).show();
            })();
        @elseif ($errors->any())
            new bootstrap.Modal(document.getElementById('prenotazioneModal')).show();
        @endif
    });
</script>
@endpush
