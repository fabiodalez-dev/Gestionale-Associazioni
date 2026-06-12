{{-- Storico richieste di modifica dati del socio (area soci / area account).
     Variabili: $richieste (Collection), $service (ModificaDatiService). --}}
@if ($richieste->isNotEmpty())
    <div class="card">
        <div class="card-header"><i class="bi bi-clock-history me-2"></i>Le mie richieste</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th class="ps-3">Inviata il</th><th>Modifiche proposte</th><th>Stato</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($richieste as $richiesta)
                            @php $motivo = $service->motivoRifiuto($richiesta); @endphp
                            <tr>
                                <td class="ps-3 small">{{ $richiesta->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="small">
                                    @foreach ($service->proposteVisibili($richiesta) as $campo => $valore)
                                        <div>{{ \App\Services\Membri\ModificaDatiService::LABELS[$campo] ?? $campo }}: <strong>{{ $valore }}</strong></div>
                                    @endforeach
                                </td>
                                <td>
                                    @if ($richiesta->stato === \App\Models\RichiestaModificaDati::STATO_APPROVATA)
                                        <span class="badge bg-success">Approvata</span>
                                    @elseif ($richiesta->stato === \App\Models\RichiestaModificaDati::STATO_RIFIUTATA)
                                        <span class="badge bg-danger">Rifiutata</span>
                                        @if ($motivo)
                                            <div class="small text-muted mt-1">Motivo: {{ $motivo }}</div>
                                        @endif
                                    @else
                                        <span class="badge bg-warning text-dark">In attesa</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
