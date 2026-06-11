@extends('layouts.app')

@section('title', 'Eventi - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-calendar-event me-2"></i>Eventi</h1>
            <p class="text-muted mb-0">Gestione eventi di {{ session('associazione_nome', 'Manager') }}</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#eventoModal" id="btnNuovoEvento">
            <i class="bi bi-plus-lg me-1"></i>Nuovo Evento
        </button>
    </div>

    <form method="GET" action="{{ route('eventi.index') }}" class="row g-2 mb-3">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="{{ $search }}" class="form-control"
                       placeholder="Cerca per titolo, descrizione o luogo...">
                <button type="submit" class="btn btn-outline-primary">Cerca</button>
                @if ($search !== '')
                    <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary" title="Azzera ricerca">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Titolo</th>
                            <th>Data e Ora</th>
                            <th>Luogo</th>
                            <th class="text-center">Pubblicazione</th>
                            <th class="text-center">Tessere create</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($eventi as $evento)
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $evento->titolo }}</span>
                                    @if ($evento->descrizione)
                                        <div class="text-muted small text-truncate" style="max-width: 300px;">{{ $evento->descrizione }}</div>
                                    @endif
                                </td>
                                <td>{{ $evento->data_evento?->format('d/m/Y H:i') }}</td>
                                <td>{{ $evento->luogo ?: '-' }}</td>
                                <td class="text-center">
                                    @if ($evento->stato_pubblicazione === \App\Models\Evento::STATO_PUBBLICATO)
                                        <span class="badge bg-success">Pubblicato</span>
                                    @elseif ($evento->stato_pubblicazione === \App\Models\Evento::STATO_ARCHIVIATO)
                                        <span class="badge bg-dark">Archiviato</span>
                                    @else
                                        <span class="badge bg-secondary">Bozza</span>
                                    @endif
                                    @if ($evento->isAPagamento())
                                        <span class="badge bg-warning text-dark" title="Evento a pagamento">€ {{ number_format((float) $evento->prezzo, 2, ',', '.') }}</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($evento->tessere_create_count > 0)
                                        <span class="badge bg-primary">{{ $evento->tessere_create_count }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="{{ route('eventi.partecipanti.index', $evento) }}" class="btn btn-outline-primary" title="Partecipanti">
                                            <i class="bi bi-people"></i>
                                        </a>
                                        <a href="{{ route('eventi.prenotazioni.index', $evento) }}" class="btn btn-outline-primary" title="Prenotazioni frontend">
                                            <i class="bi bi-ticket-perforated"></i>
                                        </a>
                                        @if ($slugAssociazione && $evento->stato_pubblicazione === \App\Models\Evento::STATO_PUBBLICATO)
                                            <a href="{{ route('pubblico.eventi.show', ['associazione' => $slugAssociazione, 'evento' => $evento->id]) }}"
                                               class="btn btn-outline-secondary" title="Anteprima pubblica" target="_blank" rel="noopener">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        @endif
                                        <a href="{{ route('eventi.index', array_filter(['edit' => $evento->id, 'search' => $search])) }}"
                                           class="btn btn-outline-secondary" title="Modifica">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" action="{{ route('eventi.destroy', $evento) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare questo evento?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Elimina"
                                                    style="border-top-left-radius:0;border-bottom-left-radius:0;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x d-block mb-2" style="font-size: 2rem;"></i>
                                    @if ($search !== '')
                                        Nessun evento corrisponde alla ricerca.
                                    @else
                                        Nessun evento presente. Crea il primo evento!
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($eventi->hasPages())
            <div class="card-footer bg-white">
                {{ $eventi->links() }}
            </div>
        @endif
    </div>

    {{-- Modale crea/modifica evento --}}
    <div class="modal fade" id="eventoModal" tabindex="-1" aria-labelledby="eventoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="POST"
                  action="{{ $editEvento ? route('eventi.update', $editEvento) : route('eventi.store') }}"
                  class="modal-content">
                @csrf
                @if ($editEvento)
                    @method('PUT')
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="eventoModalLabel">
                        {{ $editEvento ? 'Modifica Evento' : 'Nuovo Evento' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="titolo" class="form-label">Titolo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('titolo') is-invalid @enderror" id="titolo" name="titolo"
                               value="{{ old('titolo', $editEvento?->titolo) }}" required maxlength="255">
                        @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-12 col-md-6 mb-3">
                            <label for="data_evento" class="form-label">Data e Ora <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control @error('data_evento') is-invalid @enderror"
                                   id="data_evento" name="data_evento"
                                   value="{{ old('data_evento', $editEvento?->data_evento?->format('Y-m-d\TH:i')) }}" required>
                            @error('data_evento')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-6 mb-3">
                            <label for="data_fine" class="form-label">Fine evento</label>
                            <input type="datetime-local" class="form-control @error('data_fine') is-invalid @enderror"
                                   id="data_fine" name="data_fine"
                                   value="{{ old('data_fine', $editEvento?->data_fine?->format('Y-m-d\TH:i')) }}">
                            @error('data_fine')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="luogo" class="form-label">Luogo</label>
                        <input type="text" class="form-control @error('luogo') is-invalid @enderror" id="luogo" name="luogo"
                               value="{{ old('luogo', $editEvento?->luogo) }}" maxlength="255">
                        @error('luogo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control @error('descrizione') is-invalid @enderror" id="descrizione"
                                  name="descrizione" rows="3">{{ old('descrizione', $editEvento?->descrizione) }}</textarea>
                        @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Sezione: Pubblicazione frontend (prenotazioni dal mini-sito pubblico) --}}
                    <hr>
                    <h6 class="mb-1"><i class="bi bi-globe2 me-1"></i>Pubblicazione frontend</h6>
                    <p class="text-muted small mb-3">
                        Visibilità e regole di prenotazione sul sito pubblico dell'associazione.
                        @unless ($frontendAttivo)
                            <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Il frontend pubblico non è attivo per questa associazione.</span>
                        @endunless
                    </p>
                    <div class="row">
                        <div class="col-12 col-md-4 mb-3">
                            <label for="stato_pubblicazione" class="form-label">Stato</label>
                            <select class="form-select @error('stato_pubblicazione') is-invalid @enderror"
                                    id="stato_pubblicazione" name="stato_pubblicazione">
                                @foreach (\App\Models\Evento::STATI_PUBBLICAZIONE as $statoPubblicazione)
                                    <option value="{{ $statoPubblicazione }}"
                                            @selected(old('stato_pubblicazione', $editEvento?->stato_pubblicazione ?? \App\Models\Evento::STATO_BOZZA) === $statoPubblicazione)>
                                        {{ ucfirst($statoPubblicazione) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('stato_pubblicazione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-4 mb-3">
                            <label for="capienza" class="form-label">Capienza</label>
                            <input type="number" min="1" class="form-control @error('capienza') is-invalid @enderror"
                                   id="capienza" name="capienza" placeholder="Illimitata"
                                   value="{{ old('capienza', $editEvento?->capienza) }}">
                            <div class="form-text">Vuota = illimitata. Non riducibile sotto i posti già prenotati.</div>
                            @error('capienza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-4 mb-3">
                            <label for="prezzo" class="form-label">Prezzo a posto (€)</label>
                            <input type="number" min="0" step="0.01" class="form-control @error('prezzo') is-invalid @enderror"
                                   id="prezzo" name="prezzo"
                                   value="{{ old('prezzo', $editEvento?->prezzo ?? '0.00') }}">
                            <div class="form-text">0 = gratuito.</div>
                            @error('prezzo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 col-md-6 mb-3">
                            <label for="iscrizioni_dal" class="form-label">Apertura iscrizioni</label>
                            <input type="datetime-local" class="form-control @error('iscrizioni_dal') is-invalid @enderror"
                                   id="iscrizioni_dal" name="iscrizioni_dal"
                                   value="{{ old('iscrizioni_dal', $editEvento?->iscrizioni_dal?->format('Y-m-d\TH:i')) }}">
                            @error('iscrizioni_dal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-6 mb-3">
                            <label for="iscrizioni_al" class="form-label">Chiusura iscrizioni</label>
                            <input type="datetime-local" class="form-control @error('iscrizioni_al') is-invalid @enderror"
                                   id="iscrizioni_al" name="iscrizioni_al"
                                   value="{{ old('iscrizioni_al', $editEvento?->iscrizioni_al?->format('Y-m-d\TH:i')) }}">
                            @error('iscrizioni_al')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 col-md-4 mb-3">
                            <label for="max_posti_per_prenotazione" class="form-label">Max posti per prenotazione</label>
                            <input type="number" min="1" max="255" class="form-control @error('max_posti_per_prenotazione') is-invalid @enderror"
                                   id="max_posti_per_prenotazione" name="max_posti_per_prenotazione"
                                   value="{{ old('max_posti_per_prenotazione', $editEvento?->max_posti_per_prenotazione ?? 4) }}">
                            @error('max_posti_per_prenotazione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-4 mb-3">
                            <label for="annullamento_entro_ore" class="form-label">Annullamento entro (ore)</label>
                            <input type="number" min="0" max="65535" class="form-control @error('annullamento_entro_ore') is-invalid @enderror"
                                   id="annullamento_entro_ore" name="annullamento_entro_ore"
                                   value="{{ old('annullamento_entro_ore', $editEvento?->annullamento_entro_ore ?? 24) }}">
                            <div class="form-text">Ore prima dell'evento entro cui l'utente può annullare.</div>
                            @error('annullamento_entro_ore')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-4 mb-3">
                            <label class="form-label d-block">Lista d'attesa</label>
                            <div class="form-check form-switch mt-2">
                                <input type="hidden" name="lista_attesa_attiva" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="lista_attesa_attiva"
                                       name="lista_attesa_attiva" value="1"
                                       @checked(old('lista_attesa_attiva', $editEvento?->lista_attesa_attiva ?? true))>
                                <label class="form-check-label" for="lista_attesa_attiva">Attiva a evento pieno</label>
                            </div>
                            @error('lista_attesa_attiva')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label d-block">Iscrizioni</label>
                            <div class="form-check form-switch mt-2">
                                <input type="hidden" name="iscrizioni_aperte" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="iscrizioni_aperte"
                                       name="iscrizioni_aperte" value="1"
                                       @checked(old('iscrizioni_aperte', $editEvento?->iscrizioni_aperte ?? true))>
                                <label class="form-check-label" for="iscrizioni_aperte">Iscrizioni aperte</label>
                            </div>
                            <div class="form-text">Chiusura manuale: prevale sulla finestra di apertura/chiusura.</div>
                            @error('iscrizioni_aperte')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label d-block">Approvazione</label>
                            <div class="form-check form-switch mt-2">
                                <input type="hidden" name="richiede_approvazione" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="richiede_approvazione"
                                       name="richiede_approvazione" value="1"
                                       @checked(old('richiede_approvazione', $editEvento?->richiede_approvazione ?? false))>
                                <label class="form-check-label" for="richiede_approvazione">Richiede approvazione</label>
                            </div>
                            <div class="form-text">Le prenotazioni restano "in approvazione" (posti riservati) finché non le confermi.</div>
                            @error('richiede_approvazione')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="immagine_url" class="form-label">Immagine (URL)</label>
                        <input type="url" class="form-control @error('immagine_url') is-invalid @enderror"
                               id="immagine_url" name="immagine_url" placeholder="https://..."
                               value="{{ old('immagine_url', $editEvento?->immagine_url) }}" maxlength="2048">
                        @error('immagine_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>{{ $editEvento ? 'Aggiorna' : 'Crea' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('eventoModal');

            // Modale auto-aperta quando si arriva con ?edit=... o dopo un errore di validazione
            @if ($editEvento || $errors->any())
                new bootstrap.Modal(modalEl).show();
            @endif

            // Chiudendo la modale di modifica si torna alla lista "pulita"
            @if ($editEvento)
                modalEl.addEventListener('hidden.bs.modal', function () {
                    window.location.href = @json(route('eventi.index', array_filter(['search' => $search])));
                });
            @endif
        });
    </script>
@endpush
