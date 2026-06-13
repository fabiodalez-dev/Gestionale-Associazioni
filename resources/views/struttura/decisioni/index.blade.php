@extends('layouts.app')

@section('title', 'Decisioni')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-check2-circle me-2"></i>Decisioni</h1>
</div>

@include('partials.flash')

<p class="text-muted">
    Archivio delle decisioni dell'associazione: votazioni approvate nelle riunioni del direttivo
    e delibere di ammissione dei soci.
</p>

<form method="GET" action="{{ route('decisioni.index') }}" class="row g-2 mb-3">
    <div class="col-md-6">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="search" class="form-control" placeholder="Cerca per testo…" value="{{ $search }}">
        </div>
    </div>
    <div class="col-md-3">
        <select name="anno" class="form-select">
            <option value="">Tutti gli anni</option>
            @foreach ($anni as $a)
                <option value="{{ $a }}" @selected($anno === $a)>{{ $a }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-auto">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
        <a href="{{ route('decisioni.index') }}" class="btn btn-outline-secondary">Azzera</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th>Decisione</th>
                <th>Contesto</th>
                <th>Esito</th>
                <th>Verbale</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($decisioni as $d)
                <tr>
                    <td>{{ $d['data']?->format('d/m/Y') }}</td>
                    <td>
                        @if ($d['tipo'] === 'votazione')
                            <span class="badge bg-primary"><i class="bi bi-people me-1"></i>Votazione</span>
                        @else
                            <span class="badge bg-info"><i class="bi bi-person-plus me-1"></i>Ammissione</span>
                        @endif
                    </td>
                    <td>
                        <div class="fw-semibold">{{ $d['titolo'] }}</div>
                        @if (! empty($d['note']))<div class="small text-muted">{{ $d['note'] }}</div>@endif
                    </td>
                    <td class="text-muted">{{ $d['contesto'] }}</td>
                    <td>
                        <span class="badge bg-success">{{ $d['esito'] }}</span>
                        @if (! empty($d['numerico']))<div class="small text-muted mt-1">{{ $d['numerico'] }}</div>@endif
                    </td>
                    <td>
                        @if (! empty($d['verbale_id']))
                            <a href="{{ route('verbali.index', ['edit' => $d['verbale_id']]) }}" class="text-decoration-none">
                                <i class="bi bi-journal-text me-1"></i>{{ $d['verbale_numero'] ?? 'Apri' }}
                            </a>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">Nessuna decisione trovata.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
