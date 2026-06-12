@extends('layouts.app')

@section('title', 'Donazioni')

@php
    $badgeStato = [
        \App\Models\Donazione::STATO_PAGATA => 'success',
        \App\Models\Donazione::STATO_IN_ATTESA => 'warning',
        \App\Models\Donazione::STATO_ANNULLATA => 'secondary',
    ];
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-heart me-2"></i>Donazioni</h1>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-4">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Totale incassato</div>
            <div class="fs-5 fw-semibold text-success">&euro; {{ number_format($totalePagate, 2, ',', '.') }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Donazioni pagate</div>
            <div class="fs-5 fw-semibold">{{ $numeroPagate }}</div>
        </div></div>
    </div>
</div>

<form method="GET" action="{{ route('donazioni.index') }}" class="row g-2 mb-3">
    <div class="col-auto">
        <label for="filtroStato" class="form-label mb-0 small text-muted">Stato</label>
        <select name="stato" id="filtroStato" class="form-select" onchange="this.form.submit()">
            <option value="">Tutte</option>
            @foreach (\App\Models\Donazione::STATI as $stato)
                <option value="{{ $stato }}" @selected($filtroStato === $stato)>{{ ucfirst(str_replace('_', ' ', $stato)) }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Data</th>
                <th>Donatore</th>
                <th>Email</th>
                <th class="text-end">Importo</th>
                <th>Stato</th>
                <th>Gateway</th>
                <th>Transazione</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($donazioni as $donazione)
                <tr>
                    <td>{{ ($donazione->pagata_at ?? $donazione->created_at)?->format('d/m/Y H:i') }}</td>
                    <td>
                        {{ $donazione->anonimo ? 'Anonimo' : ($donazione->donatore_nome ?: '—') }}
                        @if ($donazione->anonimo && filled($donazione->donatore_nome))
                            <span class="badge bg-secondary ms-1" title="Il nome non è mostrato pubblicamente">anonima</span>
                        @endif
                    </td>
                    <td>{{ $donazione->donatore_email ?: '—' }}</td>
                    <td class="text-end fw-semibold">&euro; {{ number_format((float) $donazione->importo, 2, ',', '.') }}</td>
                    <td><span class="badge bg-{{ $badgeStato[$donazione->stato] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $donazione->stato)) }}</span></td>
                    <td>{{ $donazione->gateway ? ucfirst($donazione->gateway) : '—' }}</td>
                    <td class="text-muted small">{{ $donazione->transaction_id ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Nessuna donazione ricevuta.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $donazioni->links() }}
@endsection
