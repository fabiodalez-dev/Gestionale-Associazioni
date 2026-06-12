{{-- Widget dashboard "Le mie attività" (f.4 piano "Gestione 2"): attività
     aperte assegnate all'utente corrente (o non assegnate), ordinate per
     scadenza. Partial autonomo: calcola da sé i dati per non toccare il
     DashboardController (stesso pattern di eventi-vita._top-partecipanti). --}}
@php
    $tenantIdAttivita = \App\Models\AttivitaAdmin::currentAssociazioneId();
    $mieAttivita = $tenantIdAttivita !== null && \Illuminate\Support\Facades\Route::has('attivita-admin.index')
        ? \App\Models\AttivitaAdmin::query()
            ->aperte()
            ->where(fn ($q) => $q->where('assegnatario_id', auth()->id())->orWhereNull('assegnatario_id'))
            ->orderByRaw('CASE WHEN scadenza IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scadenza')
            ->limit(5)
            ->get()
        : collect();
@endphp

@if ($tenantIdAttivita !== null && \Illuminate\Support\Facades\Route::has('attivita-admin.index'))
    <div class="card mb-4">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-check2-square me-1 text-primary"></i>Le mie attività</span>
            <a href="{{ route('attivita-admin.index', ['assegnatario' => 'mie']) }}" class="small">Tutte</a>
        </div>
        <div class="card-body p-0">
            @if ($mieAttivita->isEmpty())
                <p class="text-muted small p-3 mb-0">Nessuna attività aperta. Ben fatto!</p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($mieAttivita as $att)
                        @php
                            $inRitardoWidget = $att->scadenza !== null && $att->scadenza->isPast() && ! $att->scadenza->isToday();
                        @endphp
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <span class="fw-semibold">{{ $att->titolo }}</span>
                                @if ($att->assegnatario_id === null)
                                    <span class="badge bg-secondary-subtle text-dark">non assegnata</span>
                                @endif
                            </div>
                            @if ($att->scadenza)
                                <span class="badge {{ $inRitardoWidget ? 'bg-danger' : ($att->scadenza->isToday() ? 'bg-warning text-dark' : 'bg-light text-dark border') }} text-nowrap">
                                    {{ $att->scadenza->format('d/m/Y') }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
