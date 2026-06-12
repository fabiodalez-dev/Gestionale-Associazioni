@extends('layouts.app')

@section('title', 'Attività - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-check2-square me-2"></i>Attività</h1>
            <p class="text-muted mb-0">Promemoria e cose da fare del team amministrativo.</p>
        </div>
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#nuovaAttivita"
                aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="nuovaAttivita">
            <i class="bi bi-plus-lg me-1"></i>Nuova attività
        </button>
    </div>

    {{-- Form di creazione (f.4) --}}
    <div class="collapse {{ $errors->any() ? 'show' : '' }}" id="nuovaAttivita">
        <div class="card mb-3">
            <div class="card-body">
                <form method="POST" action="{{ route('attivita-admin.store') }}">
                    @csrf
                    @include('operativita.attivita._form', ['attivitaCorrente' => null])
                    <div class="text-end mt-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea attività</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Filtri --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('attivita-admin.index') }}" class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="filtro_stato">Stato</label>
                    <select class="form-select form-select-sm" id="filtro_stato" name="stato" onchange="this.form.submit()">
                        <option value="{{ \App\Models\AttivitaAdmin::STATO_APERTA }}" @selected($stato === \App\Models\AttivitaAdmin::STATO_APERTA)>Aperte</option>
                        <option value="{{ \App\Models\AttivitaAdmin::STATO_COMPLETATA }}" @selected($stato === \App\Models\AttivitaAdmin::STATO_COMPLETATA)>Completate</option>
                        <option value="all" @selected($stato === 'all')>Tutte</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="filtro_assegnatario">Assegnatario</label>
                    <select class="form-select form-select-sm" id="filtro_assegnatario" name="assegnatario" onchange="this.form.submit()">
                        <option value="tutte" @selected($assegnatario === 'tutte')>Tutte</option>
                        <option value="mie" @selected($assegnatario === 'mie')>Le mie attività</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    @if ($attivita->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-check2-circle me-1"></i>Nessuna attività con i filtri correnti.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th style="width: 3rem"></th>
                        <th>Attività</th>
                        <th>Scadenza</th>
                        <th>Assegnatario</th>
                        <th>Collegata a</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($attivita as $item)
                        @php
                            $inRitardo = ! $item->isCompletata() && $item->scadenza !== null && $item->scadenza->isPast() && ! $item->scadenza->isToday();
                        @endphp
                        <tr class="{{ $item->isCompletata() ? 'text-muted' : '' }}">
                            <td>
                                <form method="POST" action="{{ route('attivita-admin.toggle', $item) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm {{ $item->isCompletata() ? 'btn-success' : 'btn-outline-secondary' }}"
                                            title="{{ $item->isCompletata() ? 'Riapri' : 'Segna come completata' }}">
                                        <i class="bi {{ $item->isCompletata() ? 'bi-check-lg' : 'bi-circle' }}"></i>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <span class="fw-semibold {{ $item->isCompletata() ? 'text-decoration-line-through' : '' }}">{{ $item->titolo }}</span>
                                @if ($item->descrizione)
                                    <div class="small text-muted">{{ \Illuminate\Support\Str::limit($item->descrizione, 120) }}</div>
                                @endif
                                @if ($item->isCompletata() && $item->completata_at)
                                    <div class="small text-success">Completata il {{ $item->completata_at->format('d/m/Y H:i') }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($item->scadenza)
                                    {{ $item->scadenza->format('d/m/Y') }}
                                    @if ($inRitardo)
                                        <span class="badge bg-danger">In ritardo</span>
                                    @elseif (! $item->isCompletata() && $item->scadenza->isToday())
                                        <span class="badge bg-warning text-dark">Oggi</span>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small">{{ $item->assegnatario?->nome_completo ?? $item->assegnatario?->email ?? '—' }}</td>
                            <td class="small">
                                @if ($item->notabile instanceof \App\Models\Socio)
                                    <a href="{{ route('soci.show', $item->notabile) }}"><i class="bi bi-person me-1"></i>{{ $item->notabile->cognome }} {{ $item->notabile->nome }}</a>
                                @elseif ($item->notabile instanceof \App\Models\Evento)
                                    <i class="bi bi-calendar-event me-1"></i>{{ $item->notabile->titolo }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#editAttivita-{{ $item->id }}" aria-expanded="false" title="Modifica">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="{{ route('attivita-admin.destroy', $item) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questa attività?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <tr class="collapse" id="editAttivita-{{ $item->id }}">
                            <td colspan="6" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('attivita-admin.update', $item) }}">
                                    @csrf
                                    @method('PUT')
                                    @include('operativita.attivita._form', ['attivitaCorrente' => $item])
                                    <div class="text-end mt-2">
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Salva modifiche</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $attivita->links() }}
        </div>
    @endif
@endsection
