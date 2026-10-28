@extends('layouts.app')

@section('title', 'Proposte dei soci')

@php
    $statoColore = ['in_revisione' => 'warning', 'pubblicata' => 'secondary', 'da_esaminare' => 'primary', 'in_odg' => 'info', 'accolta' => 'success', 'respinta' => 'danger', 'non_ammessa' => 'dark'];
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-lightbulb me-2"></i>Proposte dei soci</h1>
    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#impostazioniProposte" aria-expanded="false" aria-controls="impostazioniProposte">
        <i class="bi bi-gear me-1"></i>Impostazioni
    </button>
</div>

@include('partials.flash')

<div class="collapse mb-3 @if ($errors->has('proposte_soglia')) show @endif" id="impostazioniProposte">
    <form method="POST" action="{{ route('proposte.impostazioni') }}" class="card card-body row g-2 flex-row align-items-end">
        @csrf
        <div class="col-md-3">
            <label class="form-label small" for="soglia">Adesioni necessarie</label>
            <input type="number" name="proposte_soglia" id="soglia" min="1" class="form-control form-control-sm" value="{{ old('proposte_soglia', $impostazioni->proposte_soglia) }}" required>
        </div>
        <div class="col-md-3">
            <input type="hidden" name="proposte_soglia_percentuale" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="proposte_soglia_percentuale" value="1" id="perc" @checked($impostazioni->proposte_soglia_percentuale)>
                <label class="form-check-label small" for="perc">In percentuale dei soci attivi</label>
            </div>
        </div>
        <div class="col-md-4">
            <input type="hidden" name="proposte_moderazione" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="proposte_moderazione" value="1" id="moder" @checked($impostazioni->proposte_moderazione)>
                <label class="form-check-label small" for="moder">La segreteria verifica le proposte prima di pubblicarle</label>
            </div>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">Salva</button></div>
        <div class="col-12 form-text">Soglia attuale: {{ $soglia }} adesioni.</div>
    </form>
</div>

<form method="GET" action="{{ route('proposte.index') }}" class="row g-2 mb-3">
    <div class="col-md-5">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="search" class="form-control" placeholder="Cerca per titolo…" value="{{ $search }}">
        </div>
    </div>
    <div class="col-md-3">
        <select name="stato" class="form-select" aria-label="Stato">
            <option value="attive" @selected($stato === 'attive')>In corso</option>
            <option value="tutte" @selected($stato === 'tutte')>Tutte</option>
            @foreach (\App\Models\Proposta::STATI as $valore => $etichetta)
                <option value="{{ $valore }}" @selected($stato === $valore)>{{ $etichetta }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-auto"><button type="submit" class="btn btn-outline-primary">Filtra</button></div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Proposta</th>
                <th>Per</th>
                <th class="text-center">Adesioni</th>
                <th>Stato</th>
                <th>Presentata</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($proposte as $proposta)
                <tr>
                    <td>
                        <a href="{{ route('proposte.show', $proposta) }}" class="fw-semibold text-decoration-none">{{ $proposta->titolo }}</a>
                        <div class="small text-muted">{{ $proposta->proponente?->nome_completo }} · {{ \App\Models\Proposta::CATEGORIE[$proposta->categoria] ?? $proposta->categoria }}</div>
                    </td>
                    <td class="small">{{ \App\Models\Proposta::DESTINATARI[$proposta->destinatario] ?? $proposta->destinatario }}</td>
                    <td class="text-center tabular">{{ $proposta->adesioni_count }}</td>
                    <td><span class="badge bg-{{ $statoColore[$proposta->stato] ?? 'secondary' }}">{{ $proposta->statoLabel() }}</span></td>
                    <td class="small text-muted">{{ $proposta->created_at?->format('d/m/Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Nessuna proposta.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $proposte->links() }}
@endsection
