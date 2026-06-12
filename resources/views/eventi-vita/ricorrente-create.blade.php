@extends('layouts.app')

@section('title', 'Nuovo evento ricorrente - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-arrow-repeat me-2"></i>Nuovo evento ricorrente</h1>
            <p class="text-muted mb-0">Genera una serie di eventi (settimanale o mensile) fino a una data, max {{ $maxOccorrenze }} occorrenze.</p>
        </div>
        <a href="{{ route('calendario-eventi.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-calendar3 me-1"></i>Calendario
        </a>
    </div>

    <form method="POST" action="{{ route('eventi-ricorrenti.store') }}" class="card">
        @csrf
        <div class="card-body">
            <h6 class="mb-3">Dati dell'evento</h6>
            <div class="mb-3">
                <label for="titolo" class="form-label">Titolo <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="titolo" name="titolo"
                       value="{{ old('titolo') }}" required maxlength="255">
                @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="row">
                <div class="col-12 col-md-6 mb-3">
                    <label for="data_evento" class="form-label">Primo evento (data e ora) <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control @error('data_evento') is-invalid @enderror"
                           id="data_evento" name="data_evento" value="{{ old('data_evento') }}" required>
                    @error('data_evento')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-6 mb-3">
                    <label for="data_fine" class="form-label">Fine evento (opzionale)</label>
                    <input type="datetime-local" class="form-control @error('data_fine') is-invalid @enderror"
                           id="data_fine" name="data_fine" value="{{ old('data_fine') }}">
                    <div class="form-text">La durata viene replicata su ogni occorrenza.</div>
                    @error('data_fine')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mb-3">
                <label for="luogo" class="form-label">Luogo</label>
                <input type="text" class="form-control @error('luogo') is-invalid @enderror" id="luogo" name="luogo"
                       value="{{ old('luogo') }}" maxlength="255">
                @error('luogo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label for="descrizione" class="form-label">Descrizione</label>
                <textarea class="form-control @error('descrizione') is-invalid @enderror" id="descrizione"
                          name="descrizione" rows="3">{{ old('descrizione') }}</textarea>
                @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <hr>
            <h6 class="mb-3"><i class="bi bi-arrow-repeat me-1"></i>Ricorrenza</h6>
            <div class="row">
                <div class="col-12 col-md-6 mb-3">
                    <label for="frequenza" class="form-label">Frequenza <span class="text-danger">*</span></label>
                    <select class="form-select @error('frequenza') is-invalid @enderror" id="frequenza" name="frequenza" required>
                        @foreach ($frequenze as $frequenza)
                            <option value="{{ $frequenza }}" @selected(old('frequenza') === $frequenza)>{{ ucfirst($frequenza) }}</option>
                        @endforeach
                    </select>
                    @error('frequenza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-6 mb-3">
                    <label for="fino_al" class="form-label">Ripeti fino al <span class="text-danger">*</span></label>
                    <input type="date" class="form-control @error('fino_al') is-invalid @enderror"
                           id="fino_al" name="fino_al" value="{{ old('fino_al') }}" required>
                    <div class="form-text">Ultima data utile (inclusa). Massimo {{ $maxOccorrenze }} occorrenze.</div>
                    @error('fino_al')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <hr>
            <h6 class="mb-1"><i class="bi bi-globe2 me-1"></i>Pubblicazione frontend</h6>
            <p class="text-muted small mb-3">Le impostazioni valgono per tutte le occorrenze della serie.</p>
            <div class="row">
                <div class="col-12 col-md-4 mb-3">
                    <label for="stato_pubblicazione" class="form-label">Stato</label>
                    <select class="form-select @error('stato_pubblicazione') is-invalid @enderror"
                            id="stato_pubblicazione" name="stato_pubblicazione">
                        @foreach (\App\Models\Evento::STATI_PUBBLICAZIONE as $statoPubblicazione)
                            <option value="{{ $statoPubblicazione }}"
                                    @selected(old('stato_pubblicazione', \App\Models\Evento::STATO_BOZZA) === $statoPubblicazione)>
                                {{ ucfirst($statoPubblicazione) }}
                            </option>
                        @endforeach
                    </select>
                    @error('stato_pubblicazione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-4 mb-3">
                    <label for="capienza" class="form-label">Capienza</label>
                    <input type="number" min="1" class="form-control @error('capienza') is-invalid @enderror"
                           id="capienza" name="capienza" placeholder="Illimitata" value="{{ old('capienza') }}">
                    @error('capienza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-4 mb-3">
                    <label for="prezzo" class="form-label">Prezzo a posto (€)</label>
                    <input type="number" min="0" step="0.01" class="form-control @error('prezzo') is-invalid @enderror"
                           id="prezzo" name="prezzo" value="{{ old('prezzo', '0.00') }}">
                    @error('prezzo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row">
                <div class="col-12 col-md-4 mb-3">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="iscrizioni_aperte" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="iscrizioni_aperte"
                               name="iscrizioni_aperte" value="1" @checked(old('iscrizioni_aperte', true))>
                        <label class="form-check-label" for="iscrizioni_aperte">Iscrizioni aperte</label>
                    </div>
                </div>
                <div class="col-12 col-md-4 mb-3">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="richiede_approvazione" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="richiede_approvazione"
                               name="richiede_approvazione" value="1" @checked(old('richiede_approvazione', false))>
                        <label class="form-check-label" for="richiede_approvazione">Richiede approvazione</label>
                    </div>
                </div>
                <div class="col-12 col-md-4 mb-3">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="solo_soci" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="solo_soci"
                               name="solo_soci" value="1" @checked(old('solo_soci', false))>
                        <label class="form-check-label" for="solo_soci">Riservato ai soci</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2">
            <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary">Annulla</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-repeat me-1"></i>Crea la serie
            </button>
        </div>
    </form>
@endsection
