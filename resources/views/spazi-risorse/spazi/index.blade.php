@extends('layouts.app')

@section('title', 'Spazi - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-door-open me-2"></i>Spazi</h1>
            <p class="text-muted mb-0">Sale e locali di {{ session('associazione_nome', 'Manager') }}</p>
        </div>
        <div class="d-flex gap-2">
            @if (Route::has('spazi.prenotazioni.index'))
                <a href="{{ route('spazi.prenotazioni.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-calendar-week me-1"></i>Prenotazioni
                </a>
            @endif
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#spazioModal" id="btnNuovoSpazio">
                <i class="bi bi-plus-lg me-1"></i>Nuovo spazio
            </button>
        </div>
    </div>

    <form method="GET" action="{{ route('spazi.index') }}" class="row g-2 mb-3">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="{{ $search }}" class="form-control"
                       placeholder="Cerca per nome o descrizione...">
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary">Cerca</button>
            @if ($search !== '')
                <a href="{{ route('spazi.index') }}" class="btn btn-outline-secondary">Azzera</a>
            @endif
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Sede</th>
                        <th class="text-center">Capienza</th>
                        <th class="text-center">Prenotazioni</th>
                        <th class="text-center">Attrezzature</th>
                        <th class="text-center">Stato</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($spazi as $spazio)
                        <tr>
                            <td>
                                <strong>{{ $spazio->nome }}</strong>
                                @if ($spazio->descrizione)
                                    <div class="text-muted small">{{ \Illuminate\Support\Str::limit($spazio->descrizione, 60) }}</div>
                                @endif
                            </td>
                            <td>{{ $spazio->sede?->nome ?: '-' }}</td>
                            <td class="text-center">{{ $spazio->capienza ?? '-' }}</td>
                            <td class="text-center">{{ $spazio->prenotazioni_count }}</td>
                            <td class="text-center">{{ $spazio->attrezzature_count }}</td>
                            <td class="text-center">
                                @if ($spazio->attivo)
                                    <span class="badge bg-success">Attivo</span>
                                @else
                                    <span class="badge bg-secondary">Non attivo</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @php
                                    $spazioJson = json_encode([
                                        'id' => $spazio->id,
                                        'nome' => $spazio->nome,
                                        'descrizione' => $spazio->descrizione,
                                        'capienza' => $spazio->capienza,
                                        'sede_id' => $spazio->sede_id,
                                        'attivo' => $spazio->attivo,
                                    ]);
                                @endphp
                                <button class="btn btn-sm btn-outline-primary btn-edit-spazio" type="button"
                                        data-bs-toggle="modal" data-bs-target="#spazioModal"
                                        data-spazio="{{ $spazioJson }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="{{ route('spazi.destroy', $spazio) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questo spazio? Le prenotazioni collegate verranno rimosse.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Nessuno spazio presente. Creane uno per iniziare a gestire le prenotazioni.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $spazi->links() }}</div>

    {{-- Modale crea/modifica spazio --}}
    <div class="modal fade" id="spazioModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('spazi.store') }}" id="spazioForm">
                    @csrf
                    <input type="hidden" name="_method" id="spazio_method" value="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="spazioModalTitolo">Nuovo spazio</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="spazio_nome" class="form-label">Nome <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('nome') is-invalid @enderror" id="spazio_nome" name="nome"
                                   value="{{ old('nome') }}" maxlength="150" required>
                            @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="spazio_descrizione" class="form-label">Descrizione</label>
                            <textarea class="form-control @error('descrizione') is-invalid @enderror" id="spazio_descrizione"
                                      name="descrizione" rows="2">{{ old('descrizione') }}</textarea>
                            @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="spazio_capienza" class="form-label">Capienza</label>
                                <input type="number" min="1" class="form-control @error('capienza') is-invalid @enderror"
                                       id="spazio_capienza" name="capienza" placeholder="Non specificata" value="{{ old('capienza') }}">
                                @error('capienza')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="spazio_sede_id" class="form-label">Sede</label>
                                <select class="form-select @error('sede_id') is-invalid @enderror" id="spazio_sede_id" name="sede_id">
                                    <option value="">— Nessuna —</option>
                                    @foreach ($sedi as $sede)
                                        <option value="{{ $sede->id }}" @selected(old('sede_id') === $sede->id)>{{ $sede->nome }}</option>
                                    @endforeach
                                </select>
                                @error('sede_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="form-check form-switch">
                            <input type="hidden" name="attivo" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="spazio_attivo" name="attivo" value="1" checked>
                            <label class="form-check-label" for="spazio_attivo">Spazio attivo (prenotabile)</label>
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
        const form = document.getElementById('spazioForm');
        const titolo = document.getElementById('spazioModalTitolo');
        const metodo = document.getElementById('spazio_method');
        const azioneBase = "{{ route('spazi.store') }}";

        function resetForm() {
            form.action = azioneBase;
            metodo.value = 'POST';
            titolo.textContent = 'Nuovo spazio';
            form.reset();
            document.getElementById('spazio_attivo').checked = true;
        }

        document.getElementById('btnNuovoSpazio')?.addEventListener('click', resetForm);

        document.querySelectorAll('.btn-edit-spazio').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const s = JSON.parse(this.dataset.spazio);
                form.action = azioneBase + '/' + s.id;
                metodo.value = 'PUT';
                titolo.textContent = 'Modifica spazio';
                document.getElementById('spazio_nome').value = s.nome ?? '';
                document.getElementById('spazio_descrizione').value = s.descrizione ?? '';
                document.getElementById('spazio_capienza').value = s.capienza ?? '';
                document.getElementById('spazio_sede_id').value = s.sede_id ?? '';
                document.getElementById('spazio_attivo').checked = !!s.attivo;
            });
        });

        @if ($errors->any() && ! old('id'))
            new bootstrap.Modal(document.getElementById('spazioModal')).show();
        @endif
    });
</script>
@endpush
