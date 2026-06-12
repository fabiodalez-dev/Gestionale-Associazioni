{{--
    Card onboarding admin (f.23 del piano "Gestione 2"), inclusa in dashboard
    con @includeIf: checklist calcolata dallo stato reale del tenant, progress,
    link diretti ai passi e dismiss persistente. Visibile solo in vista tenant
    agli amministratori; sparisce al 100% o dopo il dismiss.
--}}
@php
    /** @var \App\Models\Utente|null $onboardingUtente */
    $onboardingUtente = auth()->user();
    $onboardingAssociazione = null;
    $onboarding = null;

    $onboardingAmmesso = $onboardingUtente !== null
        && ($onboardingUtente->isSuperAdmin() || $onboardingUtente->isAdminAssociazione() || $onboardingUtente->can('struttura.view'));

    // Stessa risoluzione tenant della sidebar: sessione, con fallback
    // all'associazione dell'utente (la dashboard non usa il middleware 'tenant')
    $onboardingTenantId = session('associazione_id')
        ?: ($onboardingUtente !== null && ! $onboardingUtente->isSuperAdmin() ? $onboardingUtente->associazione_id : null);

    if ($onboardingAmmesso && filled($onboardingTenantId)) {
        $onboardingAssociazione = \App\Models\Associazione::query()->find($onboardingTenantId);
    }

    if ($onboardingAssociazione !== null) {
        $onboardingChecklist = app(\App\Services\Piattaforma\OnboardingChecklist::class);

        if ($onboardingChecklist->visibile($onboardingAssociazione)) {
            $onboarding = $onboardingChecklist->per($onboardingAssociazione);
        }
    }
@endphp

@if ($onboarding !== null)
    <div class="panel mb-4" id="onboardingCard" data-percentuale="{{ $onboarding['percentuale'] }}">
        <div class="panel-header">
            <span><i class="bi bi-rocket-takeoff me-2"></i>Per iniziare: configura {{ $onboardingAssociazione->nome }}</span>
            @if (\Illuminate\Support\Facades\Route::has('onboarding.dismiss'))
                <form method="POST" action="{{ route('onboarding.dismiss') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Nascondi questa checklist">
                        <i class="bi bi-x-lg me-1"></i>Nascondi
                    </button>
                </form>
            @endif
        </div>
        <div class="p-3 pb-0">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small text-muted">{{ $onboarding['completati'] }} di {{ $onboarding['totale'] }} passi completati</span>
                <span class="fw-semibold">{{ $onboarding['percentuale'] }}%</span>
            </div>
            <div class="progress mb-2" role="progressbar" aria-label="Avanzamento configurazione"
                 aria-valuenow="{{ $onboarding['percentuale'] }}" aria-valuemin="0" aria-valuemax="100" style="height: 8px;">
                <div class="progress-bar bg-success" style="width: {{ $onboarding['percentuale'] }}%"></div>
            </div>
        </div>
        <ul class="panel-list">
            @foreach ($onboarding['passi'] as $passo)
                <li>
                    <span>
                        @if ($passo['fatto'])
                            <i class="bi bi-check-circle-fill text-success me-2"></i><span class="text-muted text-decoration-line-through">{{ $passo['etichetta'] }}</span>
                        @else
                            <i class="bi bi-circle text-muted me-2"></i>{{ $passo['etichetta'] }}
                        @endif
                    </span>
                    @unless ($passo['fatto'])
                        <a href="{{ $passo['url'] }}" class="btn btn-sm btn-outline-primary text-nowrap">
                            Vai<i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    @endunless
                </li>
            @endforeach
        </ul>
    </div>
@endif
