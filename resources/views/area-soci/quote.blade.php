@extends('area-soci.layouts.app')

@section('title', 'Le Mie Quote - '.$socio->nome_completo)

@php
    use App\Models\Quota;

    $badgeQuota = [
        Quota::STATO_PAGATA => 'bg-success',
        Quota::STATO_SCADUTA => 'bg-danger',
        Quota::STATO_IN_SCADENZA => 'bg-warning text-dark',
        Quota::STATO_DA_PAGARE => 'bg-info text-dark',
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Le Mie Quote</h1>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-cash-coin me-2"></i>Storico quote</div>
        <div class="card-body p-0">
            @if ($quote->isEmpty())
                <p class="text-muted m-3">Nessuna quota registrata.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Anno</th>
                                <th>Tipo</th>
                                <th>Importo</th>
                                <th>Scadenza</th>
                                <th>Stato</th>
                                <th>Data pagamento</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quote as $quota)
                                @php $stato = $quota->statoCalcolato($giorniPreavviso); @endphp
                                <tr>
                                    <td>{{ $quota->anno }}</td>
                                    <td>{{ $quota->tipo }}</td>
                                    <td>&euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}</td>
                                    <td>{{ $quota->data_scadenza?->format('d/m/Y') }}</td>
                                    <td><span class="badge {{ $badgeQuota[$stato] ?? 'bg-secondary' }}">{{ $stato }}</span></td>
                                    <td>{{ $quota->data_pagamento?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="text-end">
                                        @if ($quota->hasValidPaymentToken())
                                            <a class="btn btn-primary btn-sm"
                                               href="{{ \Illuminate\Support\Facades\Route::has('pagamento.show') ? route('pagamento.show', ['token' => $quota->payment_token]) : url('/pagamento?token='.$quota->payment_token) }}">
                                                <i class="bi bi-credit-card me-1"></i> Paga ora
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-credit-card me-2"></i>Pagamento quote</div>
        <div class="card-body">
            <p class="mb-0 text-muted">
                Le quote con il pulsante <strong>Paga ora</strong> possono essere saldate online in modo sicuro.
                Per le altre, contatta la tua associazione per conoscere le modalit&agrave; di pagamento.
            </p>
        </div>
    </div>
@endsection
