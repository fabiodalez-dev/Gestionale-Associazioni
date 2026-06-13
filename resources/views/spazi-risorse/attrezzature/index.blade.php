@extends('layouts.app')

@section('title', 'Inventario - '.session('app_name', config('app.name')))

@php
    $etichetteStato = [
        \App\Models\Attrezzatura::STATO_BUONO => ['Buono', 'success'],
        \App\Models\Attrezzatura::STATO_DA_RIPARARE => ['Da riparare', 'warning'],
        \App\Models\Attrezzatura::STATO_FUORI_USO => ['Fuori uso', 'danger'],
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-box-seam me-2"></i>Inventario</h1>
            <p class="text-muted mb-0">Attrezzature di {{ session('associazione_nome', 'Manager') }}</p>
        </div>
        <div class="d-flex gap-2">
            @if (Route::has('prestiti.index'))
                <a href="{{ route('prestiti.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left-right me-1"></i>Prestiti
                </a>
            @endif
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attrezzaturaModal" id="btnNuovaAttrezzatura">
                <i class="bi bi-plus-lg me-1"></i>Nuova attrezzatura
            </button>
        </div>
    </div>

    <form method="GET" action="{{ route('attrezzature.index') }}" class="row g-2 mb-3 align-items-end">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="{{ $search }}" class="form-control"
                       placeholder="Cerca per nome, descrizione o ubicazione...">
            </div>
        </div>
        <div class="col-auto">
            <select name="stato" class="form-select">
                <option value="">Tutti gli stati</option>
                @foreach ($stati as $st)
                    <option value="{{ $st }}" @selected($statoAttivo === $st)>{{ $etichetteStato[$st][0] ?? $st }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary">Filtra</button>
            @if ($search !== '' || $statoAttivo !== '')
                <a href="{{ route('attrezzature.index') }}" class="btn btn-outline-secondary">Azzera</a>
            @endif
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th class="text-center">Quantità</th>
                        <th class="text-center">Disponibili</th>
                        <th>Stato</th>
                        <th>Ubicazione</th>
                        <th class="text-end">Valore unit.</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attrezzature as $a)
                        @php
                            $inPrestito = (int) ($a->quantita_in_prestito ?? 0);
                            $disponibili = max(0, (int) $a->quantita - $inPrestito);
                            [$statoLabel, $statoColor] = $etichetteStato[$a->stato] ?? [$a->stato, 'secondary'];
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $a->nome }}</strong>
                                @if ($a->descrizione)
                                    <div class="text-muted small">{{ \Illuminate\Support\Str::limit($a->descrizione, 60) }}</div>
                                @endif
                            </td>
                            <td class="text-center">{{ $a->quantita }}</td>
                            <td class="text-center">
                                <span class="badge {{ $disponibili > 0 ? 'bg-success' : 'bg-secondary' }}">{{ $disponibili }}</span>
                            </td>
                            <td><span class="badge bg-{{ $statoColor }}">{{ $statoLabel }}</span></td>
                            <td>{{ $a->spazio?->nome ?: ($a->ubicazione ?: '-') }}</td>
                            <td class="text-end">{{ $a->valore_unitario !== null ? '€ '.number_format((float) $a->valore_unitario, 2, ',', '.') : '-' }}</td>
                            <td class="text-end">
                                @php
                                    $aJson = json_encode([
                                        'id' => $a->id,
                                        'nome' => $a->nome,
                                        'descrizione' => $a->descrizione,
                                        'quantita' => $a->quantita,
                                        'valore_unitario' => $a->valore_unitario,
                                        'stato' => $a->stato,
                                        'spazio_id' => $a->spazio_id,
                                        'ubicazione' => $a->ubicazione,
                                    ]);
                                @endphp
                                <button class="btn btn-sm btn-outline-primary btn-edit-attrezzatura" type="button"
                                        data-bs-toggle="modal" data-bs-target="#attrezzaturaModal"
                                        data-attrezzatura="{{ $aJson }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="{{ route('attrezzature.destroy', $a) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questa attrezzatura?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Nessuna attrezzatura nell'inventario.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $attrezzature->links() }}</div>

    {{-- Modale crea/modifica attrezzatura --}}
    <div class="modal fade" id="attrezzaturaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('attrezzature.store') }}" id="attrezzaturaForm">
                    @csrf
                    <input type="hidden" name="_method" id="attrezzatura_method" value="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="attrezzaturaModalTitolo">Nuova attrezzatura</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="attrezzatura_nome" class="form-label">Nome <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('nome') is-invalid @enderror" id="attrezzatura_nome" name="nome"
                                   value="{{ old('nome') }}" maxlength="150" required>
                            @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="attrezzatura_descrizione" class="form-label">Descrizione</label>
                            <textarea class="form-control @error('descrizione') is-invalid @enderror" id="attrezzatura_descrizione"
                                      name="descrizione" rows="2">{{ old('descrizione') }}</textarea>
                            @error('descrizione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="attrezzatura_quantita" class="form-label">Quantità <span class="text-danger">*</span></label>
                                <input type="number" min="1" class="form-control @error('quantita') is-invalid @enderror"
                                       id="attrezzatura_quantita" name="quantita" value="{{ old('quantita', 1) }}" required>
                                @error('quantita')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="attrezzatura_valore" class="form-label">Valore unit. (€)</label>
                                <input type="text" inputmode="decimal" class="form-control @error('valore_unitario') is-invalid @enderror"
                                       id="attrezzatura_valore" name="valore_unitario" value="{{ old('valore_unitario') }}" placeholder="0,00">
                                @error('valore_unitario')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="attrezzatura_stato" class="form-label">Stato <span class="text-danger">*</span></label>
                                <select class="form-select @error('stato') is-invalid @enderror" id="attrezzatura_stato" name="stato" required>
                                    @foreach ($stati as $st)
                                        <option value="{{ $st }}" @selected(old('stato', \App\Models\Attrezzatura::STATO_BUONO) === $st)>
                                            {{ $etichetteStato[$st][0] ?? $st }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('stato')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="attrezzatura_spazio_id" class="form-label">Spazio (ubicazione)</label>
                                <select class="form-select @error('spazio_id') is-invalid @enderror" id="attrezzatura_spazio_id" name="spazio_id">
                                    <option value="">— Nessuno —</option>
                                    @foreach ($spazi as $sp)
                                        <option value="{{ $sp->id }}" @selected(old('spazio_id') === $sp->id)>{{ $sp->nome }}</option>
                                    @endforeach
                                </select>
                                @error('spazio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="attrezzatura_ubicazione" class="form-label">Ubicazione (libera)</label>
                                <input type="text" class="form-control @error('ubicazione') is-invalid @enderror" id="attrezzatura_ubicazione"
                                       name="ubicazione" value="{{ old('ubicazione') }}" maxlength="200" placeholder="Es. Magazzino">
                                @error('ubicazione')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
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
        const form = document.getElementById('attrezzaturaForm');
        const titolo = document.getElementById('attrezzaturaModalTitolo');
        const metodo = document.getElementById('attrezzatura_method');
        const azioneBase = "{{ route('attrezzature.store') }}";

        document.getElementById('btnNuovaAttrezzatura')?.addEventListener('click', function () {
            form.action = azioneBase;
            metodo.value = 'POST';
            titolo.textContent = 'Nuova attrezzatura';
            form.reset();
            document.getElementById('attrezzatura_quantita').value = 1;
        });

        document.querySelectorAll('.btn-edit-attrezzatura').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const a = JSON.parse(this.dataset.attrezzatura);
                form.action = azioneBase + '/' + a.id;
                metodo.value = 'PUT';
                titolo.textContent = 'Modifica attrezzatura';
                document.getElementById('attrezzatura_nome').value = a.nome ?? '';
                document.getElementById('attrezzatura_descrizione').value = a.descrizione ?? '';
                document.getElementById('attrezzatura_quantita').value = a.quantita ?? 1;
                document.getElementById('attrezzatura_valore').value = a.valore_unitario ?? '';
                document.getElementById('attrezzatura_stato').value = a.stato ?? '';
                document.getElementById('attrezzatura_spazio_id').value = a.spazio_id ?? '';
                document.getElementById('attrezzatura_ubicazione').value = a.ubicazione ?? '';
            });
        });

        @if ($errors->any())
            new bootstrap.Modal(document.getElementById('attrezzaturaModal')).show();
        @endif
    });
</script>
@endpush
