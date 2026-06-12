{{--
    Tab "Tesseramento" della scheda socio (f.6 piano Vita sociale):
    timeline per anno con tessere, quote/pagamenti e presenze eventi,
    più export Excel del singolo socio. Richiede $socio in scope.
--}}
@php
    /** @var \Illuminate\Support\Collection $timelineTesseramento */
    $timelineTesseramento = app(\App\Services\Rinnovi\StoricoTesseramentoService::class)->timeline($socio);
@endphp

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h5 class="mb-0">Storico tesseramento</h5>
    <a href="{{ route('soci.storico-tesseramento.export', $socio) }}" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-excel me-1"></i>Esporta Excel
    </a>
</div>

@if ($timelineTesseramento->isEmpty())
    <p class="text-muted mb-0">Nessuna tessera, quota o presenza registrata per questo socio.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>Anno</th>
                    <th>Tessera</th>
                    <th>Quota</th>
                    <th>Pagamenti</th>
                    <th class="text-end">Presenze eventi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($timelineTesseramento as $riga)
                    <tr>
                        <td class="fw-semibold">{{ $riga['anno'] }}</td>
                        <td>
                            @forelse ($riga['tessere'] as $tessera)
                                <div>
                                    n. {{ $tessera->numero_tessera }}
                                    <span class="badge {{ $tessera->statoVisualizzato() === \App\Models\Tessera::STATO_ATTIVA ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $tessera->statoVisualizzato() }}</span>
                                    <span class="text-muted small">scad. {{ $tessera->data_scadenza->format('d/m/Y') }}</span>
                                </div>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </td>
                        <td>
                            @forelse ($riga['quote'] as $quota)
                                <div>
                                    &euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}
                                    @if ($quota->isPagata())
                                        <span class="badge text-bg-success">Pagata</span>
                                    @else
                                        <span class="badge text-bg-secondary">Da pagare</span>
                                    @endif
                                    <span class="text-muted small">{{ $quota->tipo }}</span>
                                </div>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </td>
                        <td>
                            @php
                                $pagate = $riga['quote']->filter(fn ($q) => $q->isPagata());
                            @endphp
                            @forelse ($pagate as $quota)
                                <div class="small">
                                    {{ $quota->data_pagamento?->format('d/m/Y') }}
                                    @if ($quota->metodo_pagamento)
                                        <span class="text-muted">({{ $quota->metodo_pagamento }})</span>
                                    @endif
                                </div>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </td>
                        <td class="text-end">{{ $riga['presenze'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
