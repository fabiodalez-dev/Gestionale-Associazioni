@extends('layouts.app')

@section('title', 'Ambasciatori - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-megaphone me-2"></i>Ambasciatori</h1>
            <p class="text-muted mb-0">Classifica del programma "porta un amico": iscrizioni approvate portate dai soci.</p>
        </div>
        <div class="text-end">
            <div class="h3 mb-0 text-primary">{{ $totaleApprovate }}</div>
            <div class="small text-muted">iscrizioni approvate da referral</div>
        </div>
    </div>

    @if ($ambasciatori->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-megaphone me-1"></i>Nessun socio ha ancora portato nuove iscrizioni con il proprio codice referral.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th style="width: 4rem" class="text-center">#</th>
                        <th>Socio</th>
                        <th class="text-center">Iscrizioni approvate</th>
                        <th class="text-center">Inviti totali</th>
                        <th class="text-center">Tasso di conversione</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ambasciatori as $i => $socio)
                        @php
                            $conversione = $socio->iscrizioni_totali > 0
                                ? round(($socio->iscrizioni_approvate / $socio->iscrizioni_totali) * 100)
                                : 0;
                        @endphp
                        <tr>
                            <td class="text-center fw-bold">
                                @if ($i === 0)<i class="bi bi-trophy-fill text-warning" title="1° posto"></i>
                                @else {{ $i + 1 }}
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('soci.show', $socio) }}" class="fw-semibold text-decoration-none">{{ $socio->cognome }} {{ $socio->nome }}</a>
                                <div class="small text-muted">Socio n. {{ $socio->numero_socio }}</div>
                            </td>
                            <td class="text-center"><span class="badge bg-success fs-6">{{ $socio->iscrizioni_approvate }}</span></td>
                            <td class="text-center">{{ $socio->iscrizioni_totali }}</td>
                            <td class="text-center">{{ $conversione }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
