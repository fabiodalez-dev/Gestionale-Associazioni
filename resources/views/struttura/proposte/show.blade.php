@extends('layouts.app')

@section('title', $proposta->titolo)

@section('content')
<div class="pt-3 pb-2 mb-3 border-bottom">
    <a href="{{ route('proposte.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Proposte dei soci</a>
    <h1 class="h2 mb-0">{{ $proposta->titolo }}</h1>
    <div class="text-muted">{{ $proposta->statoLabel() }} · per {{ \App\Models\Proposta::DESTINATARI[$proposta->destinatario] ?? $proposta->destinatario }}</div>
</div>

@include('partials.flash')

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-body">
                <div class="small text-muted mb-2">
                    Presentata da <strong>{{ $proposta->proponente?->nome_completo }}</strong> il {{ $proposta->created_at?->format('d/m/Y') }}
                    · {{ \App\Models\Proposta::CATEGORIE[$proposta->categoria] ?? $proposta->categoria }}
                </div>
                <div style="white-space: pre-line">{{ $proposta->testo }}</div>
                @if ($proposta->motivazione)
                    <div class="alert alert-light border mt-3 mb-0 small"><strong>Motivazione:</strong> {{ $proposta->motivazione }}</div>
                @endif
                @if ($proposta->riunione)
                    <div class="mt-3 small">All'ordine del giorno di <a href="{{ route('riunioni.show', $proposta->riunione) }}">{{ $proposta->riunione->titolo }}</a> del {{ $proposta->riunione->data_ora?->format('d/m/Y') }}.</div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">Adesioni ({{ $proposta->adesioni->count() }} su {{ $soglia }} necessarie)</div>
            <ul class="list-group list-group-flush">
                @foreach ($proposta->adesioni as $adesione)
                    <li class="list-group-item d-flex justify-content-between small">
                        <span>{{ $adesione->socio?->nome_completo }}</span>
                        <span class="text-muted">{{ $adesione->created_at?->format('d/m/Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="col-lg-4">
        @can('update', $proposta)
            @if ($proposta->stato === 'in_revisione')
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="POST" action="{{ route('proposte.pubblica', $proposta) }}" class="mb-3">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">Pubblica</button>
                        </form>
                        <form method="POST" action="{{ route('proposte.non-ammettere', $proposta) }}">
                            @csrf
                            <label class="form-label small" for="motNA">Non ammettere, con motivazione</label>
                            <textarea name="motivazione" id="motNA" class="form-control form-control-sm mb-2" rows="2" maxlength="2000" required placeholder="Es. duplicata, fuori dagli scopi dell'associazione…"></textarea>
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">Non ammettere</button>
                        </form>
                    </div>
                </div>
            @endif

            @if (in_array($proposta->stato, ['pubblicata', 'da_esaminare'], true))
                <div class="card mb-3">
                    <div class="card-header">Inserisci nell'ordine del giorno</div>
                    <div class="card-body">
                        @if ($riunioni->isEmpty())
                            <p class="small text-muted mb-0">Nessuna riunione pianificata dell'organo destinatario. <a href="{{ route('riunioni.index') }}">Crea una riunione</a>.</p>
                        @else
                            <form method="POST" action="{{ route('proposte.odg', $proposta) }}">
                                @csrf
                                <select name="riunione_id" class="form-select form-select-sm mb-2" aria-label="Riunione">
                                    @foreach ($riunioni as $riunione)
                                        <option value="{{ $riunione->id }}">{{ $riunione->data_ora?->format('d/m/Y') }} — {{ $riunione->titolo }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm w-100">Aggiungi come punto</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            @if (in_array($proposta->stato, ['pubblicata', 'da_esaminare', 'in_odg'], true))
                <div class="card">
                    <div class="card-header">Registra l'esito</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('proposte.esito', $proposta) }}">
                            @csrf
                            <select name="esito" class="form-select form-select-sm mb-2" aria-label="Esito">
                                <option value="accolta">Accolta</option>
                                <option value="respinta">Respinta</option>
                            </select>
                            <textarea name="motivazione" class="form-control form-control-sm mb-2" rows="2" maxlength="2000" placeholder="Motivazione (facoltativa)" aria-label="Motivazione"></textarea>
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100">Registra</button>
                        </form>
                        <div class="form-text">Se la proposta è all'ordine del giorno, l'esito si registra da solo con la votazione del suo punto.</div>
                    </div>
                </div>
            @endif
        @endcan
    </div>
</div>
@endsection
