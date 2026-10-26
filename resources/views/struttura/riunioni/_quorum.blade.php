@php
    /** @var \App\Models\Riunione $riunione */
    /** @var array $quorum  AssembleaService::quorum() */
    $convocazioni = array_filter([
        'Prima convocazione' => $quorum['prima'],
        'Seconda convocazione' => $quorum['seconda'],
    ]);
@endphp

<div class="card mb-4" id="quorum">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-check me-2"></i>Quorum costitutivo</span>
        @if ($quorum['costituita_in'])
            <span class="badge bg-success">Raggiunto in {{ $quorum['costituita_in'] }} convocazione</span>
        @else
            <span class="badge bg-secondary">Non raggiunto</span>
        @endif
    </div>
    <div class="card-body">
        <div class="d-flex align-items-baseline gap-2 mb-3">
            <span class="fs-3 fw-semibold tabular">{{ $quorum['presenti'] }}</span>
            <span class="text-muted">presenti con diritto di voto su <span class="tabular">{{ $quorum['aventi_diritto'] }}</span></span>
        </div>
        @if ($quorum['deleghe'] > 0)
            <p class="small text-muted mb-2"><span class="tabular">{{ $quorum['presenti_in_persona'] }}</span> di persona e <span class="tabular">{{ $quorum['deleghe'] }}</span> per delega.</p>
        @endif
        @if ($quorum['presenti_totali'] > $quorum['presenti_in_persona'])
            <p class="small text-muted mb-3">Presenti anche {{ $quorum['presenti_totali'] - $quorum['presenti_in_persona'] }} soci senza diritto di voto.</p>
        @endif

        @foreach ($convocazioni as $etichetta => $stato)
            <div class="d-flex justify-content-between align-items-start py-2 border-top">
                <div>
                    <div class="fw-semibold">{{ $etichetta }}</div>
                    <div class="small text-muted">{{ $stato['etichetta'] }}: minimo <span class="tabular">{{ $stato['minimo'] }}</span></div>
                </div>
                @if ($stato['raggiunto'])
                    <span class="text-success small"><i class="bi bi-check-circle me-1"></i>Raggiunto</span>
                @else
                    <span class="text-muted small">Mancano <span class="tabular">{{ $stato['mancano'] }}</span></span>
                @endif
            </div>
        @endforeach
    </div>
</div>
