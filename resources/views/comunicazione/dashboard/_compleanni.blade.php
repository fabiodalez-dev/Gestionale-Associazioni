{{-- Compleanni del mese (f.9 piano Gestione 2): card della dashboard,
     visibile solo in vista tenant. Autonoma: calcola i propri dati via
     CompleanniService (stesso pattern di eventi-vita._top-partecipanti). --}}
@php
    $tenantIdCompleanni = \App\Models\Socio::currentAssociazioneId();
    $compleanniDelMese = $tenantIdCompleanni !== null
        ? app(\App\Services\Comunicazione\CompleanniService::class)->compleanniDelMese($tenantIdCompleanni)
        : collect();
@endphp

@if ($tenantIdCompleanni !== null)
    <div class="card mb-4">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-cake2 me-1 text-primary"></i>Compleanni di {{ now()->translatedFormat('F') }}</span>
            <span class="badge bg-secondary">{{ $compleanniDelMese->count() }}</span>
        </div>
        <div class="card-body p-0">
            @if ($compleanniDelMese->isEmpty())
                <p class="text-muted small p-3 mb-0">Nessun socio attivo compie gli anni questo mese.</p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($compleanniDelMese->take(10) as $socio)
                        @php $oggi = (int) $socio->data_nascita?->format('d') === (int) now()->format('d'); @endphp
                        <li class="list-group-item d-flex justify-content-between align-items-center {{ $oggi ? 'bg-warning-subtle' : '' }}">
                            <div>
                                <a href="{{ route('soci.show', $socio) }}" class="fw-semibold text-decoration-none">
                                    {{ $socio->cognome }} {{ $socio->nome }}
                                </a>
                                @if ($oggi)
                                    <span class="badge bg-warning text-dark ms-1"><i class="bi bi-cake2 me-1"></i>Oggi!</span>
                                @endif
                            </div>
                            <span class="text-muted small text-nowrap">{{ $socio->data_nascita?->format('d/m') }}</span>
                        </li>
                    @endforeach
                    @if ($compleanniDelMese->count() > 10)
                        <li class="list-group-item text-muted small">
                            … e altri {{ $compleanniDelMese->count() - 10 }} questo mese.
                        </li>
                    @endif
                </ul>
            @endif
        </div>
    </div>
@endif
