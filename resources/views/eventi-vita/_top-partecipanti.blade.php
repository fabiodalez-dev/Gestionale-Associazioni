{{-- Top partecipanti agli eventi (f.12 piano Vita sociale): card della
     dashboard, visibile solo in vista tenant. Autonomo: calcola i propri
     dati via VitaSocialeService per non toccare il DashboardController. --}}
@php
    $tenantIdTopPartecipanti = \App\Models\Evento::currentAssociazioneId();
    $topPartecipanti = $tenantIdTopPartecipanti !== null
        ? app(\App\Services\EventiVita\VitaSocialeService::class)->topPartecipanti($tenantIdTopPartecipanti)
        : collect();
@endphp

@if ($tenantIdTopPartecipanti !== null)
    <div class="card mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-trophy me-1 text-warning"></i>Top partecipanti agli eventi
        </div>
        <div class="card-body p-0">
            @if ($topPartecipanti->isEmpty())
                <p class="text-muted small p-3 mb-0">Ancora nessun check-in registrato agli eventi.</p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($topPartecipanti as $posizione => $riga)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-secondary-subtle text-dark me-2">{{ $posizione + 1 }}</span>
                                <span class="fw-semibold">{{ $riga['account']->nome_completo }}</span>
                                @if ($riga['account']->socio !== null)
                                    <span class="text-muted small ms-1">socio n. {{ $riga['account']->socio->numero_socio }}</span>
                                @endif
                            </div>
                            <span class="badge bg-primary">{{ $riga['presenze'] }} {{ $riga['presenze'] === 1 ? 'presenza' : 'presenze' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
