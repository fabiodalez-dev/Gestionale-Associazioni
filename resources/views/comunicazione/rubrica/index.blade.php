@extends('layouts.app')

@section('title', 'Rubrica dei soci')

@section('content')
<div class="pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-person-lines-fill me-2"></i>Rubrica dei soci</h1>
</div>

@include('partials.flash')

<div class="row g-4">
    <div class="col-lg-6">
        <form method="POST" action="{{ route('rubrica-soci.update') }}" class="card card-body">
            @csrf @method('PUT')
            <p class="small text-muted">I soci compaiono in rubrica solo con il proprio consenso e mostrano solo i campi che scelgono. Minorenni e soci non attivi non compaiono mai.</p>
            <input type="hidden" name="rubrica_attiva" value="0">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="rubrica_attiva" value="1" id="rubAttiva" @checked($impostazioni->rubrica_attiva)>
                <label class="form-check-label" for="rubAttiva">Rubrica attiva nell'area soci</label>
            </div>
            <input type="hidden" name="rubrica_reciprocita" value="0">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="rubrica_reciprocita" value="1" id="rubReci" @checked($impostazioni->rubrica_reciprocita)>
                <label class="form-check-label" for="rubReci">Reciprocità: consulta la rubrica solo chi vi compare</label>
            </div>
            <div class="fw-semibold small mb-1">Campi che i soci possono mostrare</div>
            @foreach (\App\Models\RubricaScheda::CAMPI as $valore => $etichetta)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="rubrica_campi[]" value="{{ $valore }}" id="rubCampo-{{ $valore }}" @checked(in_array($valore, $impostazioni->rubrica_campi, true))>
                    <label class="form-check-label" for="rubCampo-{{ $valore }}">{{ $etichetta }}</label>
                </div>
            @endforeach
            <div class="mt-3"><button type="submit" class="btn btn-primary">Salva</button></div>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-body d-flex gap-4">
                <div><div class="fs-3 fw-semibold tabular">{{ $schedeAttive }}</div><div class="small text-muted">soci in rubrica</div></div>
                <div><div class="fs-3 fw-semibold tabular">{{ $contattiMese }}</div><div class="small text-muted">messaggi nell'ultimo mese</div></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Segnalazioni di uso improprio</div>
            <ul class="list-group list-group-flush small">
                @forelse ($segnalati as $contatto)
                    <li class="list-group-item">
                        <strong>{{ $contatto->mittente?->nome_completo }}</strong> → {{ $contatto->destinatario?->nome_completo }}
                        <span class="text-muted">· "{{ $contatto->oggetto }}" · segnalato il {{ $contatto->segnalato_at?->format('d/m/Y') }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nessuna segnalazione.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
