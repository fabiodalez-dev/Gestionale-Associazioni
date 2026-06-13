{{--
    Storico prestiti attrezzature del socio (f.10 piano "Organizzazione 3").
    Partial auto-contenuto: interroga i prestiti del socio direttamente, così
    da essere incluso nella scheda socio senza modificare il SocioController.
    Riceve: $socio.
--}}
@php
    $prestitiSocio = \App\Models\PrestitoAttrezzatura::query()
        ->where('socio_id', $socio->id)
        ->with('attrezzatura')
        ->orderByDesc('dal')
        ->get();
    $attiviSocio = $prestitiSocio->where('restituito_at', null);
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-arrow-left-right me-1"></i>Prestiti attrezzature</h6>
    @if (\Illuminate\Support\Facades\Route::has('prestiti.index'))
        <a href="{{ route('prestiti.index') }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-lg me-1"></i>Gestisci prestiti
        </a>
    @endif
</div>

@if ($attiviSocio->isNotEmpty())
    <p class="small text-muted mb-2">{{ $attiviSocio->count() }} prestito/i attualmente in corso.</p>
@endif

@if ($prestitiSocio->isEmpty())
    <p class="text-muted">Nessun prestito registrato per questo socio.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>Attrezzatura</th><th class="text-center">Q.tà</th>
                    <th>Dal</th><th>Restituz. prevista</th><th>Stato</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prestitiSocio as $p)
                    <tr>
                        <td>{{ $p->attrezzatura?->nome ?: '-' }}</td>
                        <td class="text-center">{{ $p->quantita }}</td>
                        <td>{{ $p->dal->format('d/m/Y') }}</td>
                        <td>{{ $p->restituzione_prevista->format('d/m/Y') }}</td>
                        <td>
                            @if (! $p->isAttivo())
                                <span class="badge bg-secondary">Restituito {{ $p->restituito_at->format('d/m/Y') }}</span>
                            @elseif ($p->isInRitardo())
                                <span class="badge bg-danger">In ritardo</span>
                            @else
                                <span class="badge bg-success">In corso</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
