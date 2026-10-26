@extends('area-soci.layouts.app')

@section('title', 'Proposte - Area Riservata')

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
        <h1 class="h2"><i class="bi bi-lightbulb me-2"></i>Proposte</h1>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#nuovaProposta" aria-expanded="false" aria-controls="nuovaProposta">
            <i class="bi bi-plus-lg me-1"></i>Nuova proposta
        </button>
    </div>

    <p class="text-muted">Proponi un'attività, una modifica al regolamento o una mozione. Con {{ $soglia }} adesioni la proposta viene esaminata dal direttivo o dall'assemblea.</p>

    <div class="collapse mb-4 @if ($errors->any() || $precompilata) show @endif" id="nuovaProposta">
        <form method="POST" action="{{ route('area-soci.proposte.store') }}" class="card card-body">
            @csrf
            <div class="mb-2">
                <label class="form-label" for="propTitolo">Titolo</label>
                <input type="text" name="titolo" id="propTitolo" class="form-control" maxlength="200" value="{{ old('titolo', $precompilata['titolo'] ?? '') }}" required>
            </div>
            <div class="mb-2">
                <label class="form-label" for="propTesto">Proposta</label>
                <textarea name="testo" id="propTesto" class="form-control" rows="5" maxlength="5000" required>{{ old('testo', $precompilata['testo'] ?? '') }}</textarea>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label" for="propCat">Tipo</label>
                    <select name="categoria" id="propCat" class="form-select">
                        @foreach (\App\Models\Proposta::CATEGORIE as $valore => $etichetta)
                            <option value="{{ $valore }}" @selected(old('categoria') === $valore)>{{ $etichetta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="propDest">Rivolta a</label>
                    <select name="destinatario" id="propDest" class="form-select">
                        @foreach (\App\Models\Proposta::DESTINATARI as $valore => $etichetta)
                            <option value="{{ $valore }}" @selected(old('destinatario') === $valore)>{{ $etichetta }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div><button type="submit" class="btn btn-primary">Invia la proposta</button></div>
        </form>
    </div>

    @forelse ($proposte as $proposta)
        @php $aderito = $mieAdesioni->has($proposta->id); @endphp
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-2">
                    <h2 class="h6 mb-1">{{ $proposta->titolo }}</h2>
                    <span class="small text-muted">{{ $proposta->statoLabel() }}</span>
                </div>
                <div class="small text-muted mb-2">di {{ $proposta->proponente?->nome_completo }} · per {{ \App\Models\Proposta::DESTINATARI[$proposta->destinatario] ?? $proposta->destinatario }}</div>
                <p class="mb-2" style="white-space: pre-line">{{ \Illuminate\Support\Str::limit($proposta->testo, 600) }}</p>
                @if ($proposta->motivazione && in_array($proposta->stato, ['non_ammessa', 'accolta', 'respinta'], true))
                    <p class="small mb-2"><strong>Motivazione:</strong> {{ $proposta->motivazione }}</p>
                @endif
                @if ($proposta->riunione && $proposta->stato === 'in_odg')
                    <p class="small mb-2">All'ordine del giorno di "{{ $proposta->riunione->titolo }}" del {{ $proposta->riunione->data_ora?->format('d/m/Y') }}.</p>
                @endif
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="small"><i class="bi bi-people me-1"></i><span class="tabular">{{ $proposta->adesioni_count }}</span> adesioni su {{ $soglia }}</span>
                    @if ($proposta->accettaAdesioni() && $proposta->socio_id !== $socio->id)
                        @if ($aderito)
                            <form method="POST" action="{{ route('area-soci.proposte.revoca', $proposta) }}">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-secondary">Revoca l'adesione</button></form>
                        @else
                            <form method="POST" action="{{ route('area-soci.proposte.aderisci', $proposta) }}">@csrf<button type="submit" class="btn btn-sm btn-primary">Aderisco</button></form>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="text-center text-muted py-5">
            <i class="bi bi-lightbulb display-5 d-block mb-2"></i>
            Nessuna proposta per ora. Sii il primo a proporre qualcosa!
        </div>
    @endforelse

    {{ $proposte->links() }}
@endsection
