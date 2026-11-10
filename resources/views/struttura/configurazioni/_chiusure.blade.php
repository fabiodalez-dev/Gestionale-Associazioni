{{-- Calendario chiusure (#34): chiusure dell'associazione o di una sede; le festività nazionali sono calcolate --}}
<div class="card mb-4" id="chiusure">
    <div class="card-header"><i class="bi bi-calendar-x me-2"></i>Calendario chiusure</div>
    <div class="card-body">
        <p class="text-muted small">
            Le scadenze, le prenotazioni degli spazi e le restituzioni delle attrezzature non cadono nei giorni di chiusura:
            si spostano al primo giorno aperto. Le festività nazionali sono già incluse e non vanno inserite.
        </p>

        <form method="POST" action="{{ route('chiusure.store') }}" class="row g-2 align-items-end mb-4">
            @csrf
            <div class="col-12 col-md-4">
                <label class="form-label small" for="chiusura_titolo">Motivo</label>
                <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="chiusura_titolo" name="titolo" maxlength="150" required placeholder="Es. Ferie estive, Festa del patrono" value="{{ old('titolo') }}">
                @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="chiusura_inizio">Dal</label>
                <input type="date" class="form-control @error('data_inizio') is-invalid @enderror" id="chiusura_inizio" name="data_inizio" required value="{{ old('data_inizio') }}">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="chiusura_fine">Al (facoltativo)</label>
                <input type="date" class="form-control @error('data_fine') is-invalid @enderror" id="chiusura_fine" name="data_fine" value="{{ old('data_fine') }}">
                @error('data_fine')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if ($sedi->isNotEmpty())
                <div class="col-12 col-md-2">
                    <label class="form-label small" for="chiusura_sede">Sede</label>
                    <select class="form-select" id="chiusura_sede" name="sede_id">
                        <option value="">Tutte le sedi</option>
                        @foreach ($sedi as $sede)
                            <option value="{{ $sede->id }}" @selected(old('sede_id') === $sede->id)>{{ $sede->nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-12 col-md-2">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="chiusura_ogni_anno" name="ogni_anno" value="1" @checked(old('ogni_anno'))>
                    <label class="form-check-label small" for="chiusura_ogni_anno">Ogni anno</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Motivo</th><th>Periodo</th><th>Sede</th><th class="text-end">Azioni</th></tr></thead>
                <tbody>
                    @forelse ($chiusure as $chiusura)
                        <tr @class(['text-muted' => ! $chiusura->attiva])>
                            <td>
                                {{ $chiusura->titolo }}
                                @if (! $chiusura->attiva)<span class="badge bg-secondary ms-1">Sospesa</span>@endif
                                @if ($chiusura->ogni_anno)<span class="badge bg-info-subtle text-info-emphasis ms-1">Ogni anno</span>@endif
                            </td>
                            <td>
                                @if ($chiusura->ogni_anno)
                                    {{ $chiusura->data_inizio->translatedFormat('j F') }}@if (! $chiusura->data_inizio->isSameDay($chiusura->data_fine)) → {{ $chiusura->data_fine->translatedFormat('j F') }}@endif
                                @else
                                    {{ $chiusura->data_inizio->format('d/m/Y') }}@if (! $chiusura->data_inizio->isSameDay($chiusura->data_fine)) → {{ $chiusura->data_fine->format('d/m/Y') }}@endif
                                @endif
                            </td>
                            <td>{{ $chiusura->sede?->nome ?? 'Tutte' }}</td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('chiusure.toggle', $chiusura->id) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">{{ $chiusura->attiva ? 'Sospendi' : 'Riattiva' }}</button>
                                </form>
                                <form method="POST" action="{{ route('chiusure.destroy', $chiusura->id) }}" class="d-inline" onsubmit="return confirm('Eliminare questa chiusura?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Elimina"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">Nessuna chiusura inserita.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-flag me-2"></i>Prossime festività nazionali</div>
    <ul class="list-group list-group-flush">
        @foreach ($prossimeFestivita as $festa)
            <li class="list-group-item d-flex justify-content-between">
                <span>{{ $festa['nome'] }}</span>
                <span class="text-muted">{{ \Illuminate\Support\Carbon::parse($festa['data'])->translatedFormat('l j F Y') }}</span>
            </li>
        @endforeach
    </ul>
</div>
