{{-- Allegati di un modello con HaAllegati: $tipo (chiave di AllegatiController::TIPI), $modello --}}
@php($allegati = $modello->getMedia(\App\Models\Verbale::COLLEZIONE_ALLEGATI))
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-paperclip me-1"></i>Allegati</span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $allegati->count() }}</span>
    </div>
    @if ($allegati->isEmpty())
        <div class="card-body text-muted small">Nessun allegato.</div>
    @else
        <ul class="list-group list-group-flush">
            @foreach ($allegati as $allegato)
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <a href="{{ route('allegati.download', [$tipo, $modello->getKey(), $allegato->id]) }}" class="text-break">
                        <i class="bi bi-file-earmark me-1"></i>{{ $allegato->file_name }}
                    </a>
                    <span class="d-flex align-items-center gap-2 text-nowrap">
                        <span class="small text-muted">{{ $allegato->human_readable_size }} · {{ $allegato->created_at?->format('d/m/Y') }}</span>
                        @can('update', $modello)
                            <form method="POST" action="{{ route('allegati.destroy', [$tipo, $modello->getKey(), $allegato->id]) }}" onsubmit="return confirm('Eliminare questo allegato?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i><span class="visually-hidden">Elimina {{ $allegato->file_name }}</span></button>
                            </form>
                        @endcan
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
    @can('update', $modello)
        <div class="card-footer">
            <form method="POST" action="{{ route('allegati.store', [$tipo, $modello->getKey()]) }}" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-start">
                @csrf
                <div class="flex-grow-1">
                    <label for="allegato-file" class="visually-hidden">File da allegare</label>
                    <input type="file" name="file" id="allegato-file" class="form-control form-control-sm @error('file') is-invalid @enderror" required>
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">PDF, documenti, fogli di calcolo, immagini o ZIP, fino a 20 MB.</div>
                </div>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>Carica</button>
            </form>
        </div>
    @endcan
</div>
