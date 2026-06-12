@extends('layouts.app')

@section('title', 'Serie ricorrente - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-arrow-repeat me-2"></i>Serie: {{ $evento->titolo }}</h1>
            <p class="text-muted mb-0">{{ $occorrenze->count() }} occorrenze — le modifiche qui sotto valgono per TUTTA la serie.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('calendario-eventi.index') }}" class="btn btn-outline-secondary"><i class="bi bi-calendar3 me-1"></i>Calendario</a>
            <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary"><i class="bi bi-list-ul me-1"></i>Elenco eventi</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Occorrenze della serie</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th class="text-center">Stato</th>
                                    <th class="text-center">Prenotazioni</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($occorrenze as $occorrenza)
                                    <tr class="{{ $occorrenza->id === $evento->id ? 'table-active' : '' }}">
                                        <td>
                                            {{ $occorrenza->data_evento->format('d/m/Y H:i') }}
                                            @if ($occorrenza->data_evento->isPast())
                                                <span class="badge bg-light text-muted">passato</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if ($occorrenza->stato_pubblicazione === \App\Models\Evento::STATO_PUBBLICATO)
                                                <span class="badge bg-success">Pubblicato</span>
                                            @elseif ($occorrenza->stato_pubblicazione === \App\Models\Evento::STATO_ARCHIVIATO)
                                                <span class="badge bg-dark">Archiviato</span>
                                            @else
                                                <span class="badge bg-secondary">Bozza</span>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ $occorrenza->prenotazioni_count }}</td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="{{ route('eventi.index', ['edit' => $occorrenza->id]) }}"
                                                   class="btn btn-outline-secondary" title="Modifica occorrenza">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="POST" action="{{ route('eventi.destroy', $occorrenza) }}" class="d-inline"
                                                      onsubmit="return confirm('Annullare SOLO questa occorrenza?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Annulla occorrenza"
                                                            style="border-top-left-radius:0;border-bottom-left-radius:0;">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <form method="POST" action="{{ route('eventi.serie.destroy', $evento) }}"
                          onsubmit="return confirm('Annullare TUTTA la serie ({{ $occorrenze->count() }} eventi)?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="bi bi-trash me-1"></i>Annulla l'intera serie
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <form method="POST" action="{{ route('eventi.serie.update', $evento) }}" class="card">
                @csrf
                @method('PUT')
                <div class="card-header bg-white fw-semibold">Modifica tutta la serie</div>
                <div class="card-body">
                    <p class="text-muted small">I campi vengono propagati a tutte le occorrenze. Le date restano proprie di ogni evento.</p>
                    <div class="mb-3">
                        <label for="titolo" class="form-label">Titolo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="titolo" name="titolo"
                               value="{{ old('titolo', $evento->titolo) }}" required maxlength="255">
                        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="luogo" class="form-label">Luogo</label>
                        <input type="text" class="form-control @error('luogo') is-invalid @enderror" id="luogo" name="luogo"
                               value="{{ old('luogo', $evento->luogo) }}" maxlength="255">
                        @error('luogo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control @error('descrizione') is-invalid @enderror" id="descrizione"
                                  name="descrizione" rows="3">{{ old('descrizione', $evento->descrizione) }}</textarea>
                        @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="stato_pubblicazione" class="form-label">Stato</label>
                            <select class="form-select @error('stato_pubblicazione') is-invalid @enderror"
                                    id="stato_pubblicazione" name="stato_pubblicazione">
                                @foreach (\App\Models\Evento::STATI_PUBBLICAZIONE as $statoPubblicazione)
                                    <option value="{{ $statoPubblicazione }}"
                                            @selected(old('stato_pubblicazione', $evento->stato_pubblicazione) === $statoPubblicazione)>
                                        {{ ucfirst($statoPubblicazione) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('stato_pubblicazione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-3 mb-3">
                            <label for="capienza" class="form-label">Capienza</label>
                            <input type="number" min="1" class="form-control @error('capienza') is-invalid @enderror"
                                   id="capienza" name="capienza" placeholder="∞" value="{{ old('capienza', $evento->capienza) }}">
                            @error('capienza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-3 mb-3">
                            <label for="prezzo" class="form-label">Prezzo (€)</label>
                            <input type="number" min="0" step="0.01" class="form-control @error('prezzo') is-invalid @enderror"
                                   id="prezzo" name="prezzo" value="{{ old('prezzo', $evento->prezzo) }}">
                            @error('prezzo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input type="hidden" name="iscrizioni_aperte" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="iscrizioni_aperte"
                               name="iscrizioni_aperte" value="1" @checked(old('iscrizioni_aperte', $evento->iscrizioni_aperte))>
                        <label class="form-check-label" for="iscrizioni_aperte">Iscrizioni aperte</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input type="hidden" name="richiede_approvazione" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="richiede_approvazione"
                               name="richiede_approvazione" value="1" @checked(old('richiede_approvazione', $evento->richiede_approvazione))>
                        <label class="form-check-label" for="richiede_approvazione">Richiede approvazione</label>
                    </div>
                    <div class="form-check form-switch">
                        <input type="hidden" name="solo_soci" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="solo_soci"
                               name="solo_soci" value="1" @checked(old('solo_soci', $evento->solo_soci))>
                        <label class="form-check-label" for="solo_soci">Riservato ai soci</label>
                    </div>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Applica a tutta la serie
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
