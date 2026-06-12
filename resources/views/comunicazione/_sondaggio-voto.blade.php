{{-- Card di voto/risultati di un sondaggio (f.11): condivisa tra area soci
     e area account. Variabili: $sondaggio, $risposta (RispostaSondaggio|null),
     $risultati (array indice→voti), $actionVota (url POST del voto). --}}
@php
    $opzioni = array_values((array) $sondaggio->opzioni);
    $totaleVoti = array_sum($risultati);
@endphp
<div class="card mb-3">
    <div class="card-header fw-semibold">
        <i class="bi bi-bar-chart me-1"></i>{{ $sondaggio->domanda }}
        @if ($sondaggio->solo_soci)
            <span class="badge bg-primary ms-1">Solo soci</span>
        @endif
    </div>
    <div class="card-body">
        @if ($sondaggio->aperto_al)
            <p class="text-muted small mb-3">
                <i class="bi bi-clock me-1"></i>Aperto fino al {{ $sondaggio->aperto_al->format('d/m/Y H:i') }}
            </p>
        @endif

        @if ($risposta === null)
            <form method="POST" action="{{ $actionVota }}">
                @csrf
                @foreach ($opzioni as $indice => $opzione)
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="opzione"
                               id="opzione-{{ $sondaggio->id }}-{{ $indice }}" value="{{ $indice }}" required>
                        <label class="form-check-label" for="opzione-{{ $sondaggio->id }}-{{ $indice }}">{{ $opzione }}</label>
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary btn-sm mt-2">
                    <i class="bi bi-check2-square me-1"></i>Vota
                </button>
            </form>
        @else
            <p class="text-success small mb-3">
                <i class="bi bi-check-circle me-1"></i>Hai votato:
                <strong>{{ $opzioni[$risposta->opzione] ?? 'Opzione n. '.($risposta->opzione + 1) }}</strong>
            </p>
            @foreach ($opzioni as $indice => $opzione)
                @php
                    $voti = $risultati[$indice] ?? 0;
                    $percentuale = $totaleVoti > 0 ? round($voti / $totaleVoti * 100) : 0;
                @endphp
                <div class="mb-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ $opzione }} @if ($indice === $risposta->opzione)<i class="bi bi-check-lg text-success"></i>@endif</span>
                        <span class="text-muted">{{ $percentuale }}%</span>
                    </div>
                    <div class="progress" role="progressbar" aria-valuenow="{{ $percentuale }}"
                         aria-valuemin="0" aria-valuemax="100" style="height: 14px;">
                        <div class="progress-bar" style="width: {{ $percentuale }}%"></div>
                    </div>
                </div>
            @endforeach
            <p class="text-muted small mb-0 mt-2">{{ $totaleVoti }} {{ $totaleVoti === 1 ? 'voto totale' : 'voti totali' }}</p>
        @endif
    </div>
</div>
