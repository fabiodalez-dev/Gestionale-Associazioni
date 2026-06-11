{{-- Risultati lista tessere (renderizzato anche in modalità AJAX) --}}
@if ($tessere->isEmpty())
    <div class="text-center text-muted py-5">
        <i class="bi bi-credit-card-2-front" style="font-size:3rem;"></i>
        <p class="mt-2 mb-0">Nessuna tessera trovata per i filtri selezionati.</p>
    </div>
@else
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Socio</th>
                    <th>N. Tessera</th>
                    <th>Anno</th>
                    <th>Emissione</th>
                    <th>Scadenza</th>
                    <th>Tipo</th>
                    <th>Stato</th>
                    <th>Evento</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tessere as $tessera)
                    @php
                        $statoVis = $tessera->statoVisualizzato();
                        $badgeClass = match ($statoVis) {
                            \App\Models\Tessera::STATO_ATTIVA => 'bg-success',
                            \App\Models\Tessera::STATO_SCADUTA => 'bg-danger',
                            \App\Models\Tessera::STATO_SOSPESA => 'bg-warning text-dark',
                            default => 'bg-secondary',
                        };
                        $giorniAllaScadenza = $tessera->data_scadenza
                            ? (int) now()->startOfDay()->diffInDays($tessera->data_scadenza, false)
                            : null;
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $tessera->socio?->cognome }} {{ $tessera->socio?->nome }}</strong>
                            <div class="small text-muted">N. socio: {{ $tessera->socio?->numero_socio }}</div>
                        </td>
                        <td>{{ $tessera->numero_tessera }}</td>
                        <td>{{ $tessera->anno_validita }}</td>
                        <td>{{ $tessera->data_emissione?->format('d/m/Y') }}</td>
                        <td>
                            {{ $tessera->data_scadenza?->format('d/m/Y') }}
                            @if ($tessera->isScadutaEffettiva())
                                <span class="badge bg-danger ms-1">Scaduta</span>
                            @elseif ($giorniAllaScadenza !== null && $giorniAllaScadenza <= $sogliaInScadenza)
                                <span class="badge bg-warning text-dark ms-1">In scadenza</span>
                            @endif
                        </td>
                        <td>{{ $tessera->socio?->tipoSocio?->nome ?? $tessera->template_tessera ?? 'Default' }}</td>
                        <td><span class="badge {{ $badgeClass }}">{{ $statoVis }}</span></td>
                        <td>{{ $tessera->eventoCreazione?->titolo ?? '—' }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('tessere.pdf', $tessera) }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="PDF tessera">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </a>
                            <a href="{{ route('tessere.stampa', $tessera) }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Stampa card">
                                <i class="bi bi-printer"></i>
                            </a>
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary btn-edit-tessera"
                                    title="Modifica"
                                    data-id="{{ $tessera->id }}"
                                    data-socio-id="{{ $tessera->socio_id }}"
                                    data-numero="{{ $tessera->numero_tessera }}"
                                    data-anno="{{ $tessera->anno_validita }}"
                                    data-emissione="{{ $tessera->data_emissione?->toDateString() }}"
                                    data-scadenza="{{ $tessera->data_scadenza?->toDateString() }}"
                                    data-stato="{{ $tessera->stato }}"
                                    data-evento-id="{{ $tessera->evento_creazione_id }}"
                                    data-update-url="{{ route('tessere.update', $tessera) }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('tessere.destroy', $tessera) }}" class="d-inline"
                                  onsubmit="return confirm('Eliminare definitivamente la tessera {{ $tessera->numero_tessera }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
