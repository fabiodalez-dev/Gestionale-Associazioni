@extends('layouts.app')

@section('title', 'Bilancio sociale '.$bilancio->anno)

@php $modificabile = $bilancio->stato === 'bozza'; @endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <div>
        <a href="{{ route('bilancio-sociale.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Bilancio sociale</a>
        <h1 class="h2 mb-0">Bilancio sociale {{ $bilancio->anno }} <span class="badge bg-secondary align-middle">{{ $bilancio->statoLabel() }}</span></h1>
        <div class="small text-muted">Dati calcolati il {{ $bilancio->generato_at?->format('d/m/Y H:i') }}</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('bilancio-sociale.pdf', $bilancio) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        @can('update', $bilancio)
            @if ($modificabile)
                <form method="POST" action="{{ route('bilancio-sociale.rigenera', $bilancio) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Aggiorna i dati</button></form>
            @elseif ($bilancio->stato === 'approvato')
                <form method="POST" action="{{ route('bilancio-sociale.riapri', $bilancio) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary">Riporta in bozza</button></form>
                <form method="POST" action="{{ route('bilancio-sociale.pubblica', $bilancio) }}" onsubmit="return confirm('Pubblicare il PDF nella sezione Trasparenza del mini-sito?');">@csrf<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-globe me-1"></i>Pubblica in Trasparenza</button></form>
            @endif
        @endcan
    </div>
</div>

@include('partials.flash')

@if ($bilancio->stato === 'pubblicato' && $bilancio->documento)
    <div class="alert alert-success small">Pubblicato il {{ $bilancio->pubblicato_at?->format('d/m/Y') }} nella sezione Trasparenza come "{{ $bilancio->documento->nome_file }}".</div>
@endif
@if ($bilancio->verbale)
    <div class="alert alert-light border small">Approvato dall'assemblea: verbale n. {{ $bilancio->verbale->numero_completo }} del {{ $bilancio->verbale->data_riunione?->format('d/m/Y') }}.</div>
@endif

<form method="POST" action="{{ route('bilancio-sociale.update', $bilancio) }}">
    @csrf @method('PUT')
    @foreach (\App\Models\BilancioSociale::SEZIONI as $chiave => $sezione)
        <div class="card mb-4">
            <div class="card-header fw-semibold">{{ $loop->iteration }}. {{ $sezione['titolo'] }}</div>
            <div class="card-body">
                @if (in_array($chiave, ['ente', 'governo', 'persone', 'attivita', 'economia'], true))
                    <div class="small bg-body-tertiary border rounded p-3 mb-3">
                        <div class="fw-semibold mb-1"><i class="bi bi-database me-1"></i>Dati dal gestionale</div>
                        @include('relazioni.bilancio-sociale._dati', ['bilancio' => $bilancio, 'sezione' => $chiave])
                    </div>
                @endif
                <label class="form-label small text-muted" for="testo-{{ $chiave }}">{{ $sezione['guida'] }}</label>
                <textarea name="testi[{{ $chiave }}]" id="testo-{{ $chiave }}" class="form-control" rows="5" maxlength="20000" @disabled(! $modificabile)>{{ old("testi.{$chiave}", $bilancio->testo($chiave)) }}</textarea>
            </div>
        </div>
    @endforeach

    @if ($modificabile)
        @can('update', $bilancio)
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-5">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva i testi</button>
            </div>
        @endcan
    @endif
</form>

@if ($modificabile)
    @can('update', $bilancio)
        <form method="POST" action="{{ route('bilancio-sociale.approva', $bilancio) }}" class="card card-body mb-5">
            @csrf
            <div class="fw-semibold mb-2">Approvazione</div>
            <label class="form-label small" for="verbaleApprovazione">Verbale dell'assemblea che lo approva (facoltativo)</label>
            <select name="verbale_id" id="verbaleApprovazione" class="form-select mb-2">
                <option value="">— Nessuno —</option>
                @foreach ($verbali as $verbale)
                    <option value="{{ $verbale->id }}">n. {{ $verbale->numero_completo }} del {{ $verbale->data_riunione?->format('d/m/Y') }} — {{ $verbale->titolo }}</option>
                @endforeach
            </select>
            <div><button type="submit" class="btn btn-outline-success" onclick="return confirm('Approvare il bilancio sociale? I testi non saranno più modificabili.');">Segna come approvato</button></div>
        </form>
    @endcan
@endif
@endsection
