@php
    /** @var \App\Models\Utente $utente */
    $utente = auth()->user();
    $isSuperAdmin = $utente?->isSuperAdmin() ?? false;
    // Per admin_associazione/operatore il tenant è quello dell'utente anche se la sessione non è ancora popolata
    $sidebarHasAssoc = filled(session('associazione_id')) || (! $isSuperAdmin && filled($utente?->associazione_id));
    $associazioneNome = session('associazione_nome') ?? (! $isSuperAdmin ? $utente?->associazione?->nome : null) ?? 'Manager';

    // Le rotte dei moduli vengono aggiunte progressivamente: i link a moduli
    // non ancora migrati restano disabilitati (#) senza rompere la sidebar.
    $nav = fn (string $name, array $params = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $params) : '#';
    $can = fn (string $permission) => $isSuperAdmin || ($utente?->can($permission) ?? false);

    $emailActive = request()->routeIs('comunicazioni.*', 'email-templates.*', 'email-coda.*', 'email-log.*', 'email-impostazioni.*');
    $apiActive = request()->routeIs('api-keys.*', 'api-docs.*');
@endphp
<nav class="sidebar" id="mainSidebar" aria-label="Navigazione principale">
    <div class="sidebar-inner">
        <div class="sidebar-brand">
            <i class="bi bi-people-fill"></i>
            <span>{{ $associazioneNome }}</span>
            <button type="button" class="theme-toggle-icon" id="themeToggle" aria-label="Cambia tema chiaro/scuro" title="Tema chiaro/scuro">
                <i class="bi bi-moon theme-label-light"></i>
                <i class="bi bi-sun theme-label-dark"></i>
            </button>
        </div>

        @if ($isSuperAdmin)
            @php
                $sidebarAssocList = \App\Models\Associazione::query()
                    ->where('attiva', true)
                    ->orderBy('nome')
                    ->get(['id', 'nome']);
            @endphp
            <div class="sidebar-assoc-picker">
                <label class="sidebar-assoc-label" for="sidebarAssocSelect"><i class="bi bi-building me-1"></i>Associazione</label>
                <form method="POST" action="{{ route('tenant.switch') }}" id="sidebarAssocForm">
                    @csrf
                    <select class="sidebar-assoc-select" id="sidebarAssocSelect" name="associazione_id" onchange="this.form.submit()">
                        <option value="">-- Seleziona --</option>
                        @foreach ($sidebarAssocList as $sa)
                            <option value="{{ $sa->id }}" @selected((string) session('associazione_id') === (string) $sa->id)>{{ $sa->nome }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        @endif

        <hr>

        @if ($sidebarHasAssoc)
            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
                <span>Menu Principale</span>
            </h6>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                        <i class="bi bi-speedometer2 me-2"></i>
                        Dashboard
                    </a>
                </li>
                @if ($can('soci.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('soci.*') ? 'active' : '' }}" href="{{ $nav('soci.index') }}">
                        <i class="bi bi-people me-2"></i>
                        Soci
                    </a>
                </li>
                @endif
                @if ($can('tessere.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('tessere.*') ? 'active' : '' }}" href="{{ $nav('tessere.index') }}">
                        <i class="bi bi-credit-card-2-front me-2"></i>
                        Tessere
                    </a>
                </li>
                @endif
                @if ($can('quote.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('quote.*') ? 'active' : '' }}" href="{{ $nav('quote.index') }}">
                        <i class="bi bi-credit-card me-2"></i>
                        Quote
                    </a>
                </li>
                @endif
                @if ($can('eventi.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('eventi.*') ? 'active' : '' }}" href="{{ $nav('eventi.index') }}">
                        <i class="bi bi-calendar-event me-2"></i>
                        Eventi
                    </a>
                </li>
                @endif
                @if ($can('email.view'))
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $emailActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#emailSubmenu" role="button" aria-expanded="{{ $emailActive ? 'true' : 'false' }}">
                        <span><i class="bi bi-envelope me-2"></i>Email &amp; Notifiche</span>
                        <i class="bi bi-chevron-down small"></i>
                    </a>
                    <div class="collapse {{ $emailActive ? 'show' : '' }}" id="emailSubmenu">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('comunicazioni.*') ? 'active' : '' }}" href="{{ $nav('comunicazioni.index') }}"><i class="bi bi-send me-2"></i>Invia Comunicazione</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-templates.*') ? 'active' : '' }}" href="{{ $nav('email-templates.index') }}"><i class="bi bi-palette me-2"></i>Template Email</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-coda.*') ? 'active' : '' }}" href="{{ $nav('email-coda.index') }}"><i class="bi bi-hourglass-split me-2"></i>Coda Invio</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-log.*') ? 'active' : '' }}" href="{{ $nav('email-log.index') }}"><i class="bi bi-clock-history me-2"></i>Storico Email</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-impostazioni.*') ? 'active' : '' }}" href="{{ $nav('email-impostazioni.index') }}"><i class="bi bi-gear me-2"></i>Impostazioni SMTP</a></li>
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('documenti.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('documenti.*') ? 'active' : '' }}" href="{{ $nav('documenti.index') }}">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        Documenti
                    </a>
                </li>
                @endif
            </ul>
        @else
            <div class="px-3 mt-4 mb-3">
                <div class="alert alert-warning small mb-0 py-2">
                    <i class="bi bi-info-circle me-1"></i>Seleziona un'associazione per accedere al menu.
                </div>
            </div>
        @endif

        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
            <span>Amministrazione</span>
        </h6>
        <ul class="nav flex-column mb-2">
            @if ($isSuperAdmin)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('associazioni.*') ? 'active' : '' }}" href="{{ $nav('associazioni.index') }}">
                    <i class="bi bi-buildings me-2"></i>
                    Associazioni
                </a>
            </li>
            @endif
            @if ($sidebarHasAssoc && $can('struttura.view'))
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('configurazioni.*') && request('section') !== 'sedi' ? 'active' : '' }}" href="{{ $nav('configurazioni.index') }}">
                    <i class="bi bi-gear me-2"></i>
                    Impostazioni
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('amministratori.*') ? 'active' : '' }}" href="{{ $nav('amministratori.index') }}">
                    <i class="bi bi-person-badge me-2"></i>
                    Utenti
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('configurazioni.*') && request('section') === 'sedi' ? 'active' : '' }}" href="{{ $nav('configurazioni.index', ['section' => 'sedi']) }}">
                    <i class="bi bi-geo-alt me-2"></i>
                    Sedi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('config-campi.*') ? 'active' : '' }}" href="{{ $nav('config-campi.index') }}">
                    <i class="bi bi-journal-plus me-2"></i>
                    Campi Personalizzati
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('config-tags.*') ? 'active' : '' }}" href="{{ $nav('config-tags.index') }}">
                    <i class="bi bi-tags me-2"></i>
                    Gestione Tag
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('categorie-socio.*') ? 'active' : '' }}" href="{{ $nav('categorie-socio.index') }}">
                    <i class="bi bi-bookmark-star me-2"></i>
                    Categorie Socio
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('tipi-socio.*') ? 'active' : '' }}" href="{{ $nav('tipi-socio.index') }}">
                    <i class="bi bi-person-badge me-2"></i>
                    Tipi Socio
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('config-gruppi.*') ? 'active' : '' }}" href="{{ $nav('config-gruppi.index') }}">
                    <i class="bi bi-collection me-2"></i>
                    Gruppi Dinamici
                </a>
            </li>
            @php
                // Account del frontend pubblico con richiesta "diventa socio" pendente
                $richiesteSocioPendenti = \Illuminate\Support\Facades\Route::has('accounts.index')
                    ? \App\Models\Account::query()
                        ->whereNotNull('richiesta_socio_at')
                        ->whereNull('socio_id')
                        ->where('attivo', true)
                        ->count()
                    : 0;
            @endphp
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('accounts.*') ? 'active' : '' }}" href="{{ $nav('accounts.index') }}">
                    <i class="bi bi-person-vcard me-2"></i>
                    Account frontend
                    @if ($richiesteSocioPendenti > 0)
                        <span class="badge bg-warning text-dark ms-1" title="Richieste socio pendenti">{{ $richiesteSocioPendenti }}</span>
                    @endif
                </a>
            </li>
            @endif
            @if (($sidebarHasAssoc || $isSuperAdmin) && $can('api.view'))
            <li class="nav-item">
                <a class="nav-link d-flex justify-content-between align-items-center {{ $apiActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#apiSubmenu" role="button" aria-expanded="{{ $apiActive ? 'true' : 'false' }}">
                    <span><i class="bi bi-hdd-network me-2"></i>API REST</span>
                    <i class="bi bi-chevron-down small"></i>
                </a>
                <div class="collapse {{ $apiActive ? 'show' : '' }}" id="apiSubmenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('api-keys.*') ? 'active' : '' }}" href="{{ $nav('api-keys.index') }}"><i class="bi bi-key me-2"></i>Chiavi API</a></li>
                        <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('api-docs.*') ? 'active' : '' }}" href="{{ $nav('api-docs.index') }}"><i class="bi bi-book me-2"></i>Documentazione</a></li>
                    </ul>
                </div>
            </li>
            @endif
        </ul>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <i class="bi bi-person-circle"></i>
                <div>
                    <div class="sidebar-user-name">{{ $utente?->nome_completo ?: 'Utente' }}</div>
                    <div class="sidebar-user-role">{{ $utente->ruolo ?? 'ruolo' }}</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('profile.show') }}" class="btn btn-outline-secondary btn-sm flex-fill {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                    <i class="bi bi-person-gear me-1"></i>Profilo
                </a>
                <form method="POST" action="{{ route('logout') }}" class="flex-fill">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-box-arrow-right me-1"></i>Esci
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
