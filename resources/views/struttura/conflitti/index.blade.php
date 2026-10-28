@extends('layouts.app')

@section('title', 'Conflitti di interesse '.$anno)

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <h1 class="h2"><i class="bi bi-shield-exclamation me-2"></i>Conflitti di interesse</h1>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" action="{{ route('conflitti.index') }}" class="d-flex gap-2">
            <input type="number" name="anno" class="form-control form-control-sm" style="width: 6rem" min="2000" max="2100" value="{{ $anno }}" aria-label="Anno">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Vai</button>
        </form>
        <a href="{{ route('conflitti.pdf', ['anno' => $anno]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
    </div>
</div>

@include('partials.flash')

@php $mancanti = $titolari->reject(fn ($s) => $dichiarazioni->has($s->id)); @endphp

<div class="card mb-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <strong class="tabular">{{ $titolari->count() - $mancanti->count() }}</strong> dichiarazioni su <strong class="tabular">{{ $titolari->count() }}</strong> titolari di cariche nel {{ $anno }}.
            @if ($raccoltaAnno === $anno)
                <span class="text-success small ms-2"><i class="bi bi-envelope-check me-1"></i>Raccolta in corso: promemoria mensile a chi manca.</span>
            @endif
        </div>
        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('conflitti.raccolta') }}">
                @csrf
                <input type="hidden" name="anno" value="{{ $anno }}">
                <button type="submit" class="btn btn-sm btn-primary" @disabled($mancanti->isEmpty())>
                    <i class="bi bi-send me-1"></i>{{ $raccoltaAnno === $anno ? 'Invia ora la richiesta a chi manca' : 'Avvia la raccolta '.$anno }}
                </button>
            </form>
            @if ($raccoltaAnno !== null)
                <form method="POST" action="{{ route('conflitti.raccolta.ferma') }}">
                    @csrf @method('DELETE')
                    <input type="hidden" name="anno" value="{{ $anno }}">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Ferma la raccolta</button>
                </form>
            @endif
        </div>
    </div>
</div>

<h2 class="h5">Dichiarazioni annuali</h2>
<div class="table-responsive mb-4">
    <table class="table align-middle">
        <thead>
            <tr>
                <th>Titolare</th>
                <th>Cariche</th>
                <th>Dichiarazione</th>
                <th class="text-end"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($titolari as $socio)
                @php $dichiarazione = $dichiarazioni->get($socio->id); @endphp
                <tr>
                    <td class="fw-semibold">{{ $socio->nome_completo }}</td>
                    <td class="small">{{ $socio->cariche->pluck('ruolo')->unique()->join(', ') }}</td>
                    <td class="small">
                        @if (! $dichiarazione)
                            <span class="text-danger">Mancante</span>
                        @elseif ($dichiarazione->nessun_conflitto)
                            <span class="text-success">Nessun conflitto</span> <span class="text-muted">· {{ $dichiarazione->dichiarata_at->format('d/m/Y') }}</span>
                        @else
                            <div class="text-warning fw-semibold">Interessi dichiarati <span class="text-muted fw-normal">· {{ $dichiarazione->dichiarata_at->format('d/m/Y') }}</span></div>
                            <div style="white-space: pre-line">{{ $dichiarazione->interessi }}</div>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('create', \App\Models\Riunione::class)
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#dich-{{ $socio->id }}" aria-expanded="false">Registra</button>
                        @endcan
                    </td>
                </tr>
                @can('create', \App\Models\Riunione::class)
                <tr class="collapse" id="dich-{{ $socio->id }}">
                    <td colspan="4">
                        <form method="POST" action="{{ route('conflitti.dichiarazioni.store') }}" class="row g-2 align-items-end">
                            @csrf
                            <input type="hidden" name="socio_id" value="{{ $socio->id }}">
                            <input type="hidden" name="anno" value="{{ $anno }}">
                            <div class="col-md-3">
                                <input type="hidden" name="nessun_conflitto" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="nessun_conflitto" value="1" id="nc-{{ $socio->id }}" @checked($dichiarazione?->nessun_conflitto)>
                                    <label class="form-check-label small" for="nc-{{ $socio->id }}">Nessun conflitto</label>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <textarea name="interessi" class="form-control form-control-sm" rows="2" maxlength="5000" placeholder="Interessi dichiarati (dal modulo firmato)" aria-label="Interessi">{{ $dichiarazione?->interessi }}</textarea>
                            </div>
                            <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">Salva</button></div>
                        </form>
                    </td>
                </tr>
                @endcan
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">Nessuna carica nel {{ $anno }}.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<h2 class="h5">Conflitti dichiarati nelle riunioni</h2>
<div class="table-responsive">
    <table class="table align-middle">
        <thead><tr><th>Riunione</th><th>Punto</th><th>Socio</th><th>Motivazione</th></tr></thead>
        <tbody>
            @forelse ($conflitti as $conflitto)
                @php $punto = $conflitto->riunione?->odg[$conflitto->punto_indice] ?? null; @endphp
                <tr>
                    <td><a href="{{ route('riunioni.show', $conflitto->riunione_id) }}" class="text-decoration-none">{{ $conflitto->riunione?->titolo }}</a> <span class="small text-muted">{{ $conflitto->riunione?->data_ora?->format('d/m/Y') }}</span></td>
                    <td class="small">{{ $conflitto->punto_indice + 1 }}. {{ is_array($punto) ? ($punto['punto'] ?? '') : $punto }}</td>
                    <td>{{ $conflitto->socio?->nome_completo }}</td>
                    <td class="small text-muted">{{ $conflitto->motivazione ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">Nessun conflitto dichiarato nel {{ $anno }}.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
