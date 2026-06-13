@extends('layouts.app')

@section('title', $turno->titolo.' - '.session('app_name', config('app.name')))

@section('content')
    @php
        $confermate = $turno->assegnazioni->where('stato', \App\Models\TurnoAssegnazione::STATO_CONFERMATO);
        $proposte = $turno->assegnazioni->where('stato', \App\Models\TurnoAssegnazione::STATO_PROPOSTO);
        $rinunce = $turno->assegnazioni->where('stato', \App\Models\TurnoAssegnazione::STATO_RINUNCIATO);
    @endphp

    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-person-raised-hand me-2"></i>{{ $turno->titolo }}</h1>
            <p class="text-muted mb-0">
                {{ $turno->data->format('d/m/Y') }} · {{ substr((string) $turno->ora_inizio, 0, 5) }}–{{ substr((string) $turno->ora_fine, 0, 5) }}
                <span class="badge {{ $turno->isAperto() ? 'bg-primary' : 'bg-secondary' }} ms-1">{{ ucfirst($turno->stato) }}</span>
                @if ($turno->auto_conferma)<span class="badge bg-info text-dark">Auto-conferma</span>@endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('turni.edit', $turno) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Modifica</a>
            <a href="{{ route('turni.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Turni</a>
            <form method="POST" action="{{ route('turni.destroy', $turno) }}" onsubmit="return confirm('Eliminare il turno?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card text-center"><div class="card-body py-2">
            <div class="h4 mb-0">{{ $confermate->count() }}/{{ $turno->posti }}</div><small class="text-muted">Confermati</small>
        </div></div></div>
        <div class="col-md-3"><div class="card text-center"><div class="card-body py-2">
            <div class="h4 mb-0">{{ $proposte->count() }}</div><small class="text-muted">Da confermare</small>
        </div></div></div>
        <div class="col-md-3"><div class="card text-center"><div class="card-body py-2">
            <div class="h4 mb-0">{{ $turno->postiDisponibili() }}</div><small class="text-muted">Posti liberi</small>
        </div></div></div>
        <div class="col-md-3"><div class="card text-center"><div class="card-body py-2">
            <div class="h4 mb-0">{{ rtrim(rtrim(number_format($durataOre, 2, ',', ''), '0'), ',') }} h</div><small class="text-muted">Durata</small>
        </div></div></div>
    </div>

    @if ($turno->mansione || $turno->evento || $turno->spazio || $turno->note)
        <div class="card mb-3"><div class="card-body">
            <dl class="row mb-0">
                @if ($turno->mansione)<dt class="col-sm-2">Mansione</dt><dd class="col-sm-10">{{ $turno->mansione->nome }}</dd>@endif
                @if ($turno->evento)<dt class="col-sm-2">Evento</dt><dd class="col-sm-10">{{ $turno->evento->titolo }}</dd>@endif
                @if ($turno->spazio)<dt class="col-sm-2">Spazio</dt><dd class="col-sm-10">{{ $turno->spazio->nome }}</dd>@endif
                @if ($turno->note)<dt class="col-sm-2">Note</dt><dd class="col-sm-10">{{ $turno->note }}</dd>@endif
            </dl>
        </div></div>
    @endif

    <div class="row g-3">
        {{-- Volontari assegnati --}}
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i>Volontari assegnati</div>
                <div class="card-body">
                    @if ($turno->assegnazioni->whereIn('stato', ['proposto','confermato'])->isEmpty())
                        <p class="text-muted mb-0">Nessun volontario assegnato.</p>
                    @else
                        <ul class="list-group list-group-flush mb-3">
                            @foreach ($proposte as $a)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span><i class="bi bi-hourglass-split text-warning me-1"></i>{{ $a->socio?->nome_completo }} <span class="badge bg-warning text-dark">Proposto</span></span>
                                    <span class="d-flex gap-1">
                                        <form method="POST" action="{{ route('turni.conferma', [$turno, $a]) }}">@csrf
                                            <button class="btn btn-sm btn-success" title="Conferma"><i class="bi bi-check-lg"></i></button>
                                        </form>
                                        <form method="POST" action="{{ route('turni.rimuovi', [$turno, $a]) }}" onsubmit="return confirm('Rimuovere?');">@csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" title="Rimuovi"><i class="bi bi-x-lg"></i></button>
                                        </form>
                                    </span>
                                </li>
                            @endforeach
                            @foreach ($confermate as $a)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span><i class="bi bi-check-circle-fill text-success me-1"></i>{{ $a->socio?->nome_completo }}
                                        @if ($a->ore_effettive !== null)<span class="badge bg-secondary">{{ $a->ore_effettive }} h</span>@endif
                                    </span>
                                    <form method="POST" action="{{ route('turni.rimuovi', [$turno, $a]) }}" onsubmit="return confirm('Rimuovere?');">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Rimuovi"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- f.5 Registra presenze a fine turno --}}
                    @if ($confermate->isNotEmpty())
                        <form method="POST" action="{{ route('turni.presenze', $turno) }}">
                            @csrf
                            <h6 class="mb-2"><i class="bi bi-clipboard-check me-1"></i>Registra presenze (ore effettive)</h6>
                            @foreach ($confermate as $a)
                                <div class="row g-2 align-items-center mb-1">
                                    <div class="col-7">{{ $a->socio?->nome_completo }}</div>
                                    <div class="col-5">
                                        <input type="number" step="0.25" min="0" max="24" class="form-control form-control-sm"
                                               name="ore[{{ $a->id }}]" value="{{ $a->ore_effettive ?? rtrim(rtrim(number_format($durataOre, 2, '.', ''), '0'), '.') }}"
                                               placeholder="Ore (vuoto = assente)">
                                    </div>
                                </div>
                            @endforeach
                            <div class="text-end mt-2">
                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Registra presenze e chiudi</button>
                            </div>
                        </form>
                    @endif

                    @if ($rinunce->isNotEmpty())
                        <hr>
                        <details>
                            <summary class="text-muted small">Rinunce ({{ $rinunce->count() }})</summary>
                            <ul class="small text-muted mt-2 mb-0">
                                @foreach ($rinunce as $a)
                                    <li>{{ $a->socio?->nome_completo }} @if ($a->motivo_rinuncia)— {{ $a->motivo_rinuncia }}@endif</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            </div>
        </div>

        {{-- Suggerimenti volontari (f.3) --}}
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-stars me-1"></i>Volontari suggeriti</div>
                <div class="card-body">
                    @if ($turno->postiDisponibili() < 1)
                        <p class="text-muted mb-0">Posti esauriti.</p>
                    @elseif ($suggeriti->isEmpty())
                        <p class="text-muted mb-0">Nessun volontario disponibile da suggerire.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($suggeriti as $s)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span>
                                        {{ $s['socio']->nome_completo }}
                                        @if ($s['ha_mansione'])<span class="badge bg-success" title="Ha la mansione richiesta"><i class="bi bi-tools"></i></span>@endif
                                        @if ($s['disponibile'])<span class="badge bg-info text-dark" title="Disponibile in questa fascia"><i class="bi bi-calendar-check"></i></span>@endif
                                    </span>
                                    <span class="d-flex gap-1">
                                        <form method="POST" action="{{ route('turni.assegna', $turno) }}">
                                            @csrf
                                            <input type="hidden" name="socio_id" value="{{ $s['socio']->id }}">
                                            <button class="btn btn-sm btn-outline-secondary" title="Proponi">+</button>
                                        </form>
                                        <form method="POST" action="{{ route('turni.assegna', $turno) }}">
                                            @csrf
                                            <input type="hidden" name="socio_id" value="{{ $s['socio']->id }}">
                                            <input type="hidden" name="conferma" value="1">
                                            <button class="btn btn-sm btn-success" title="Assegna confermato"><i class="bi bi-check-lg"></i></button>
                                        </form>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
