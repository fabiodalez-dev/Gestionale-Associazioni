{{-- Navigazione dell'area riservata account (pills, responsive) --}}
@php
    /** @var \App\Models\Associazione $associazione */
    /** @var \App\Models\Account $account */
    $account ??= auth('account')->user();
@endphp
<ul class="nav nav-pills flex-nowrap overflow-auto gap-1 mb-4 pb-1">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('pubblico.account.dashboard') ? 'active' : '' }}"
           href="{{ route('pubblico.account.dashboard', $associazione->slug) }}">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('pubblico.account.prenotazioni.*') ? 'active' : '' }}"
           href="{{ route('pubblico.account.prenotazioni.index', $associazione->slug) }}">
            <i class="bi bi-ticket-perforated me-1"></i>Prenotazioni
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('pubblico.account.storico') ? 'active' : '' }}"
           href="{{ route('pubblico.account.storico', $associazione->slug) }}">
            <i class="bi bi-clock-history me-1"></i>Storico
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('pubblico.account.profilo') ? 'active' : '' }}"
           href="{{ route('pubblico.account.profilo', $associazione->slug) }}">
            <i class="bi bi-person me-1"></i>Profilo
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('pubblico.account.preferenze') ? 'active' : '' }}"
           href="{{ route('pubblico.account.preferenze', $associazione->slug) }}">
            <i class="bi bi-toggles me-1"></i>Preferenze
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('pubblico.account.privacy') ? 'active' : '' }}"
           href="{{ route('pubblico.account.privacy', $associazione->slug) }}">
            <i class="bi bi-shield-lock me-1"></i>Privacy
        </a>
    </li>
    @if ($account->socio_id !== null)
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('pubblico.account.tessera') ? 'active' : '' }}"
               href="{{ route('pubblico.account.tessera', $associazione->slug) }}">
                <i class="bi bi-card-heading me-1"></i>Tessera &amp; quote
            </a>
        </li>
    @else
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('pubblico.account.diventa-socio') ? 'active' : '' }}"
               href="{{ route('pubblico.account.diventa-socio', $associazione->slug) }}">
                <i class="bi bi-person-heart me-1"></i>Diventa socio
            </a>
        </li>
    @endif
</ul>
