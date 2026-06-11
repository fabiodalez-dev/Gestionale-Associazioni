@extends('area-soci.layouts.app')

@section('title', 'Le Mie Tessere - '.$socio->nome_completo)

@php
    $badgeTessera = [
        'Attiva' => 'bg-success',
        'In Scadenza' => 'bg-warning text-dark',
        'Scaduta' => 'bg-danger',
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Le Mie Tessere</h1>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-credit-card-2-front me-2"></i>Tessere</div>
        <div class="card-body p-0">
            @if ($tessere->isEmpty())
                <p class="text-muted m-3">Nessuna tessera registrata. Contatta la tua associazione.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Anno</th>
                                <th>Numero</th>
                                <th>Emissione</th>
                                <th>Scadenza</th>
                                <th>Tipo</th>
                                <th>Stato</th>
                                <th>Documento</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tessere as $tessera)
                                @php $stato = $statiCalcolati[$tessera->id] ?? $tessera->stato; @endphp
                                <tr>
                                    <td>{{ $tessera->anno_validita }}</td>
                                    <td>{{ $tessera->numero_tessera }}</td>
                                    <td>{{ $tessera->data_emissione?->format('d/m/Y') }}</td>
                                    <td>{{ $tessera->data_scadenza?->format('d/m/Y') }}</td>
                                    <td>{{ ucfirst((string) $tessera->tipo_scadenza) }}</td>
                                    <td><span class="badge {{ $badgeTessera[$stato] ?? 'bg-secondary' }}">{{ $stato }}</span></td>
                                    <td>
                                        <a href="{{ route('area-soci.tessere.pdf', ['tessera' => $tessera->id]) }}" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
