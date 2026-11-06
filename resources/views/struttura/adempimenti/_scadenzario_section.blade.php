{{--
    Sezione "Adempimenti ricorrenti" della pagina Scadenzario (f.20 del piano
    "Organizzazione 3"): checklist per anno renderizzata DENTRO lo Scadenzario
    esistente. La sezione interroga i propri dati in autonomia (così da non
    richiedere modifiche al controller Economia) ed è visibile solo a chi può
    consultare gli adempimenti (scadenze.view).

    @param int|null $adempimentiAnno  anno della checklist (default: anno corrente)
--}}
@php
    $utenteAdemp = auth()->user();
    // Gating via policy (AdempimentoPolicy) così da rispettare i permessi
    // granulari E il fallback per ruolo quando i permessi non sono seedati.
    $puoVedereAdempimenti = $utenteAdemp
        && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Adempimento::class);
@endphp

@if ($puoVedereAdempimenti)
    @php
        $annoChecklist = (int) ($adempimentiAnno ?? request('adempimenti_anno', now()->format('Y')));
        $adempimentiSezione = \App\Models\Adempimento::query()
            ->where('attivo', true)
            ->with(['anni' => fn ($q) => $q->where('anno', $annoChecklist)])
            ->orderBy('mese_scadenza')
            ->orderBy('titolo')
            ->get();

        $mesiBrevi = [1=>'Gen',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mag',6=>'Giu',7=>'Lug',8=>'Ago',9=>'Set',10=>'Ott',11=>'Nov',12=>'Dic'];
        // Spunta abilitata se la policy lo consente su almeno un adempimento
        // (toggleChecklist → scadenze.update con fallback admin/operatore).
        $puoSpuntare = $adempimentiSezione->isNotEmpty()
            && \Illuminate\Support\Facades\Gate::allows('toggleChecklist', $adempimentiSezione->first());
        $totaleAdemp = $adempimentiSezione->count();
        $completatiAdemp = $adempimentiSezione->filter(fn ($a) => $a->anni->firstWhere('completato_at', '!=', null) !== null)->count();
    @endphp

    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-calendar2-check me-2"></i>Adempimenti ricorrenti {{ $annoChecklist }}</span>
            <div class="d-flex align-items-center gap-2">
                @if ($totaleAdemp > 0)
                    <span class="badge bg-{{ $completatiAdemp === $totaleAdemp ? 'success' : 'secondary' }}">{{ $completatiAdemp }}/{{ $totaleAdemp }} completati</span>
                @endif
                @can('create', \App\Models\Adempimento::class)
                    <a href="{{ route('adempimenti.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1"></i>Gestisci</a>
                @endcan
            </div>
        </div>
        <div class="card-body">
            @forelse ($adempimentiSezione as $adempimento)
                @php
                    $riga = $adempimento->anni->first();
                    $completato = $riga && $riga->completato_at !== null;
                @endphp
                <div class="d-flex justify-content-between align-items-center py-2 {{ ! $loop->last ? 'border-bottom' : '' }}">
                    <div>
                        <span class="badge bg-light text-dark border me-2">{{ $mesiBrevi[$adempimento->mese_scadenza] ?? '' }}</span>
                        <span class="{{ $completato ? 'text-decoration-line-through text-muted' : 'fw-semibold' }}">{{ $adempimento->titolo }}</span>
                        @if ($completato && $riga)
                            <small class="text-success ms-2"><i class="bi bi-check-circle"></i> {{ $riga->completato_at->format('d/m/Y') }}</small>
                        @endif
                        @if ($adempimento->manuale_pagina_id)
                            <a href="{{ route('manuale.show', $adempimento->manuale_pagina_id) }}" class="small ms-2" title="Procedura del manuale"><i class="bi bi-book"></i> Procedura</a>
                        @endif
                    </div>
                    @if ($puoSpuntare)
                        <form method="POST" action="{{ route('adempimenti.toggle-anno', $adempimento) }}">
                            @csrf
                            <input type="hidden" name="anno" value="{{ $annoChecklist }}">
                            <button type="submit" class="btn btn-sm {{ $completato ? 'btn-outline-secondary' : 'btn-outline-success' }}">
                                @if ($completato)
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Riapri
                                @else
                                    <i class="bi bi-check-lg me-1"></i>Segna fatto
                                @endif
                            </button>
                        </form>
                    @elseif ($completato)
                        <span class="badge bg-success">Fatto</span>
                    @else
                        <span class="badge bg-secondary">Da fare</span>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">
                    Nessun adempimento ricorrente configurato.
                    @can('create', \App\Models\Adempimento::class)
                        <a href="{{ route('adempimenti.index') }}">Configurali ora</a>.
                    @endcan
                </p>
            @endforelse
        </div>
    </div>
@endif
