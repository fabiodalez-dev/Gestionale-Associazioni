{{-- Tab "Note" della scheda socio (f.3 piano "Gestione 2"): timeline delle
     note interne admin con pin, distinte dallo storico automatico. Partial
     autonomo (carica da sé le note) per non toccare il SocioController. --}}
@php
    $noteSocio = \App\Models\NotaSocio::query()
        ->where('socio_id', $socio->id)
        ->with('autore')
        ->orderByDesc('fissata')
        ->orderByDesc('created_at')
        ->get();
    $canNote = auth()->user()?->can('create', \App\Models\NotaSocio::class) ?? false;
@endphp

@if ($canNote)
    <form method="POST" action="{{ route('soci.note.store', $socio) }}" class="mb-4">
        @csrf
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-8">
                <label class="form-label" for="nota_testo">Nuova nota interna</label>
                <textarea class="form-control @error('testo') is-invalid @enderror" id="nota_testo" name="testo"
                          rows="2" maxlength="5000" required placeholder="Visibile solo agli amministratori...">{{ old('testo') }}</textarea>
                @error('testo')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="col-6 col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="fissata" id="nota_fissata" value="1" @checked(old('fissata'))>
                    <label class="form-check-label" for="nota_fissata"><i class="bi bi-pin-angle me-1"></i>Fissa in cima</label>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
            </div>
        </div>
    </form>
@endif

@if ($noteSocio->isEmpty())
    <p class="text-muted">Nessuna nota interna su questo socio.</p>
@else
    <ul class="list-group list-group-flush">
        @foreach ($noteSocio as $nota)
            <li class="list-group-item px-0 {{ $nota->fissata ? 'bg-warning-subtle rounded px-2' : '' }}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        @if ($nota->fissata)
                            <span class="badge bg-warning text-dark me-1" title="Nota fissata"><i class="bi bi-pin-angle-fill"></i> Fissata</span>
                        @endif
                        <span style="white-space: pre-line">{{ $nota->testo }}</span>
                        <div class="text-muted small mt-1">
                            {{ $nota->created_at?->format('d/m/Y H:i') }}
                            — {{ $nota->autore?->nome_completo ?? $nota->autore?->email ?? 'Utente eliminato' }}
                        </div>
                    </div>
                    @if ($canNote)
                        <div class="text-nowrap">
                            <form method="POST" action="{{ route('soci.note.pin', [$socio, $nota]) }}" class="d-inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        title="{{ $nota->fissata ? 'Sgancia' : 'Fissa in cima' }}">
                                    <i class="bi {{ $nota->fissata ? 'bi-pin-angle-fill' : 'bi-pin-angle' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('soci.note.destroy', [$socio, $nota]) }}" class="d-inline"
                                  onsubmit="return confirm('Eliminare questa nota?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina nota">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
