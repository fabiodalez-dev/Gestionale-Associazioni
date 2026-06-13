@extends('layouts.app')

@section('title', 'Prestiti - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-arrow-left-right me-2"></i>Prestiti</h1>
            <p class="text-muted mb-0">Prestiti di attrezzature ai soci</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('attrezzature.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-box-seam me-1"></i>Inventario
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#prestitoModal"
                    id="btnNuovoPrestito" @disabled($attrezzature->isEmpty() || $soci->isEmpty())>
                <i class="bi bi-plus-lg me-1"></i>Nuovo prestito
            </button>
        </div>
    </div>

    @if ($attrezzature->isEmpty() || $soci->isEmpty())
        <div class="alert alert-info">
            Per registrare un prestito servono attrezzature in inventario e soci.
            <a href="{{ route('attrezzature.index') }}">Gestisci inventario</a>.
        </div>
    @endif

    {{-- Filtro stato --}}
    <ul class="nav nav-pills mb-3">
        @foreach (['attivi' => 'Attivi', 'ritardo' => 'In ritardo', 'restituiti' => 'Restituiti', 'tutti' => 'Tutti'] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $filtro === $key ? 'active' : '' }}" href="{{ route('prestiti.index', ['filtro' => $key]) }}">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Attrezzatura</th><th>Socio</th><th class="text-center">Q.tà</th>
                        <th>Dal</th><th>Restituz. prevista</th><th>Stato</th><th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prestiti as $p)
                        <tr>
                            <td><strong>{{ $p->attrezzatura?->nome ?: '-' }}</strong></td>
                            <td>{{ $p->socio?->nome_completo ?: '-' }}</td>
                            <td class="text-center">{{ $p->quantita }}</td>
                            <td>{{ $p->dal->format('d/m/Y') }}</td>
                            <td>{{ $p->restituzione_prevista->format('d/m/Y') }}</td>
                            <td>
                                @if (! $p->isAttivo())
                                    <span class="badge bg-secondary">Restituito {{ $p->restituito_at->format('d/m/Y') }}</span>
                                @elseif ($p->isInRitardo())
                                    <span class="badge bg-danger">In ritardo</span>
                                @else
                                    <span class="badge bg-success">In corso</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($p->isAttivo())
                                    <form method="POST" action="{{ route('prestiti.restituisci', $p) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-success" type="submit" title="Registra restituzione">
                                            <i class="bi bi-check2-circle me-1"></i>Restituito
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('prestiti.destroy', $p) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questo prestito dallo storico?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Nessun prestito in questa vista.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $prestiti->links() }}</div>

    {{-- Modale nuovo prestito --}}
    <div class="modal fade" id="prestitoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('prestiti.store') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Nuovo prestito</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="prestito_attrezzatura_id" class="form-label">Attrezzatura <span class="text-danger">*</span></label>
                            <select class="form-select @error('attrezzatura_id') is-invalid @enderror" id="prestito_attrezzatura_id" name="attrezzatura_id" required>
                                <option value="">— Seleziona —</option>
                                @foreach ($attrezzature as $a)
                                    @php $disp = max(0, (int) $a->quantita - (int) ($a->quantita_in_prestito ?? 0)); @endphp
                                    <option value="{{ $a->id }}" @selected(old('attrezzatura_id') === $a->id) @disabled($disp <= 0)>
                                        {{ $a->nome }} ({{ $disp }} disponibili)
                                    </option>
                                @endforeach
                            </select>
                            @error('attrezzatura_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="prestito_socio_id" class="form-label">Socio <span class="text-danger">*</span></label>
                            <select class="form-select @error('socio_id') is-invalid @enderror" id="prestito_socio_id" name="socio_id" required>
                                <option value="">— Seleziona —</option>
                                @foreach ($soci as $s)
                                    <option value="{{ $s->id }}" @selected(old('socio_id') === $s->id)>{{ $s->cognome }} {{ $s->nome }}</option>
                                @endforeach
                            </select>
                            @error('socio_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="prestito_quantita" class="form-label">Quantità <span class="text-danger">*</span></label>
                                <input type="number" min="1" class="form-control @error('quantita') is-invalid @enderror"
                                       id="prestito_quantita" name="quantita" value="{{ old('quantita', 1) }}" required>
                                @error('quantita')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="prestito_dal" class="form-label">Dal <span class="text-danger">*</span></label>
                                <input type="date" class="form-control @error('dal') is-invalid @enderror"
                                       id="prestito_dal" name="dal" value="{{ old('dal', now()->format('Y-m-d')) }}" required>
                                @error('dal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="prestito_restituzione" class="form-label">Restituz. prevista <span class="text-danger">*</span></label>
                                <input type="date" class="form-control @error('restituzione_prevista') is-invalid @enderror"
                                       id="prestito_restituzione" name="restituzione_prevista"
                                       value="{{ old('restituzione_prevista', now()->addWeek()->format('Y-m-d')) }}" required>
                                @error('restituzione_prevista')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="prestito_note" class="form-label">Note</label>
                            <textarea class="form-control @error('note') is-invalid @enderror" id="prestito_note" name="note" rows="2">{{ old('note') }}</textarea>
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Registra prestito</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        @if ($errors->any())
            new bootstrap.Modal(document.getElementById('prestitoModal')).show();
        @endif
    });
</script>
@endpush
