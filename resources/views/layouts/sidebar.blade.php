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

    $emailActive = request()->routeIs('comunicazioni.*', 'email-templates.*', 'email-coda.*', 'email-log.*', 'email-impostazioni.*', 'email-anteprime.*', 'annunci.*', 'newsletter.*', 'sondaggi.*');
    $apiActive = request()->routeIs('api-keys.*', 'api-docs.*');
    $registroActive = request()->routeIs('libro-soci.*', 'cessazioni.*', 'organi-sociali.*');
    $economiaActive = request()->routeIs('prima-nota.*', 'report-economico.*', 'donazioni.*');
    // Piano "Organizzazione 3": volontari/turni, spazi, inventario, progetti, riunioni e decisioni
    $organizzazioneActive = request()->routeIs('turni.*', 'volontari.*', 'spazi.*', 'attrezzature.*', 'prestiti.*', 'progetti.*', 'riunioni.*', 'decisioni.*');
    // Piano "Organizzazione 3": mini-CRM, sponsor e referral
    $relazioniActive = request()->routeIs('contatti-esterni.*', 'sponsor.*', 'ambasciatori.*');
@endphp
<nav class="sidebar" id="mainSidebar" aria-label="Navigazione principale">
    <div class="sidebar-inner">
        <div class="sidebar-brand">
            <i class="bi bi-people-fill"></i>
            <span>{{ $associazioneNome }}</span>
            <button type="button" class="theme-toggle-icon" id="globalSearchBtn" aria-label="Ricerca globale" title="Ricerca globale (Ctrl+K / ⌘K)">
                <i class="bi bi-search"></i>
            </button>
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
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('statistiche-annuali.*') ? 'active' : '' }}" href="{{ $nav('statistiche-annuali.index') }}">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        Statistiche annuali
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('attivita-admin.*') ? 'active' : '' }}" href="{{ $nav('attivita-admin.index') }}">
                        <i class="bi bi-check2-square me-2"></i>
                        Attività
                    </a>
                </li>
                @if ($can('scadenze.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('scadenzario.*') ? 'active' : '' }}" href="{{ $nav('scadenzario.index') }}">
                        <i class="bi bi-calendar-check me-2"></i>
                        Scadenzario
                    </a>
                </li>
                @endif
                @if ($can('soci.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('soci.*') ? 'active' : '' }}" href="{{ $nav('soci.index') }}">
                        <i class="bi bi-people me-2"></i>
                        Soci
                    </a>
                </li>
                @if ($can('iscrizioni.view'))
                @php
                    // Richieste di iscrizione dal form pubblico in attesa di esito
                    $richiesteIscrizionePendenti = \Illuminate\Support\Facades\Route::has('richieste-iscrizione.index')
                        ? \App\Models\RichiestaIscrizione::query()->inAttesa()->count()
                        : 0;
                @endphp
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('richieste-iscrizione.*') ? 'active' : '' }}" href="{{ $nav('richieste-iscrizione.index') }}">
                        <i class="bi bi-person-plus me-2"></i>
                        Richieste iscrizione
                        @if ($richiesteIscrizionePendenti > 0)
                            <span class="badge bg-warning text-dark ms-1" title="Richieste di iscrizione da approvare">{{ $richiesteIscrizionePendenti }}</span>
                        @endif
                    </a>
                </li>
                @endif
                @php
                    // Richieste di modifica dati anagrafici in attesa (f.19 — piano "Gestione 2")
                    $richiesteModifichePendenti = \Illuminate\Support\Facades\Route::has('richieste-modifica-dati.index')
                        ? \App\Models\RichiestaModificaDati::query()->inAttesa()->count()
                        : 0;
                @endphp
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('richieste-modifica-dati.*') ? 'active' : '' }}" href="{{ $nav('richieste-modifica-dati.index') }}">
                        <i class="bi bi-person-lines-fill me-2"></i>
                        Richieste modifiche
                        @if ($richiesteModifichePendenti > 0)
                            <span class="badge bg-warning text-dark ms-1" title="Richieste di modifica dati da gestire">{{ $richiesteModifichePendenti }}</span>
                        @endif
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
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('campagna-rinnovi.*') ? 'active' : '' }}" href="{{ $nav('campagna-rinnovi.index') }}">
                        <i class="bi bi-arrow-repeat me-2"></i>
                        Campagna rinnovi
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
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('apertura-anno.*') ? 'active' : '' }}" href="{{ $nav('apertura-anno.index') }}">
                        <i class="bi bi-calendar-plus me-2"></i>
                        Apri anno sociale
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
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('calendario-eventi.*') ? 'active' : '' }}" href="{{ $nav('calendario-eventi.index') }}">
                        <i class="bi bi-calendar3 me-2"></i>
                        Calendario
                    </a>
                </li>
                @endif
                @if ($can('soci.view'))
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $registroActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#registroSubmenu" role="button" aria-expanded="{{ $registroActive ? 'true' : 'false' }}">
                        <span><i class="bi bi-journal-bookmark me-2"></i>Registro</span>
                        <i class="bi bi-chevron-down small"></i>
                    </a>
                    <div class="collapse {{ $registroActive ? 'show' : '' }}" id="registroSubmenu">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('libro-soci.*') ? 'active' : '' }}" href="{{ $nav('libro-soci.index') }}"><i class="bi bi-book me-2"></i>Libro soci</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('cessazioni.*') ? 'active' : '' }}" href="{{ $nav('cessazioni.index') }}"><i class="bi bi-person-dash me-2"></i>Cessazioni</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('organi-sociali.*') ? 'active' : '' }}" href="{{ $nav('organi-sociali.index') }}"><i class="bi bi-diagram-3 me-2"></i>Organi sociali</a></li>
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('economia.view'))
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $economiaActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#economiaSubmenu" role="button" aria-expanded="{{ $economiaActive ? 'true' : 'false' }}">
                        <span><i class="bi bi-cash-coin me-2"></i>Economia</span>
                        <i class="bi bi-chevron-down small"></i>
                    </a>
                    <div class="collapse {{ $economiaActive ? 'show' : '' }}" id="economiaSubmenu">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('prima-nota.*') ? 'active' : '' }}" href="{{ $nav('prima-nota.index') }}"><i class="bi bi-journal-text me-2"></i>Prima nota</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('report-economico.*') ? 'active' : '' }}" href="{{ $nav('report-economico.index') }}"><i class="bi bi-graph-up me-2"></i>Report economico</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('donazioni.*') ? 'active' : '' }}" href="{{ $nav('donazioni.index') }}"><i class="bi bi-heart me-2"></i>Donazioni</a></li>
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('volontari.view') || $can('spazi.view') || $can('progetti.view') || $can('riunioni.view'))
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $organizzazioneActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#organizzazioneSubmenu" role="button" aria-expanded="{{ $organizzazioneActive ? 'true' : 'false' }}">
                        <span><i class="bi bi-diagram-3 me-2"></i>Organizzazione</span>
                        <i class="bi bi-chevron-down small"></i>
                    </a>
                    <div class="collapse {{ $organizzazioneActive ? 'show' : '' }}" id="organizzazioneSubmenu">
                        <ul class="nav flex-column ms-3">
                            @if ($can('volontari.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('turni.*', 'volontari.*') ? 'active' : '' }}" href="{{ $nav('turni.index') }}"><i class="bi bi-person-raised-hand me-2"></i>Volontari e turni</a></li>
                            @endif
                            @if ($can('spazi.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('spazi.*') ? 'active' : '' }}" href="{{ $nav('spazi.index') }}"><i class="bi bi-door-open me-2"></i>Spazi</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('attrezzature.*') ? 'active' : '' }}" href="{{ $nav('attrezzature.index') }}"><i class="bi bi-box-seam me-2"></i>Inventario</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('prestiti.*') ? 'active' : '' }}" href="{{ $nav('prestiti.index') }}"><i class="bi bi-arrow-left-right me-2"></i>Prestiti</a></li>
                            @endif
                            @if ($can('progetti.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('progetti.*') ? 'active' : '' }}" href="{{ $nav('progetti.index') }}"><i class="bi bi-kanban me-2"></i>Progetti</a></li>
                            @endif
                            @if ($can('riunioni.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('riunioni.*') ? 'active' : '' }}" href="{{ $nav('riunioni.index') }}"><i class="bi bi-people me-2"></i>Riunioni</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('decisioni.*') ? 'active' : '' }}" href="{{ $nav('decisioni.index') }}"><i class="bi bi-check2-circle me-2"></i>Decisioni</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('relazioni.view') || $can('soci.view'))
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $relazioniActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#relazioniSubmenu" role="button" aria-expanded="{{ $relazioniActive ? 'true' : 'false' }}">
                        <span><i class="bi bi-globe2 me-2"></i>Relazioni</span>
                        <i class="bi bi-chevron-down small"></i>
                    </a>
                    <div class="collapse {{ $relazioniActive ? 'show' : '' }}" id="relazioniSubmenu">
                        <ul class="nav flex-column ms-3">
                            @if ($can('relazioni.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('contatti-esterni.*') ? 'active' : '' }}" href="{{ $nav('contatti-esterni.index') }}"><i class="bi bi-person-rolodex me-2"></i>Contatti esterni</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('sponsor.*') ? 'active' : '' }}" href="{{ $nav('sponsor.index') }}"><i class="bi bi-award me-2"></i>Sponsor</a></li>
                            @endif
                            @if ($can('soci.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('ambasciatori.*') ? 'active' : '' }}" href="{{ $nav('ambasciatori.index') }}"><i class="bi bi-megaphone me-2"></i>Ambasciatori</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('email.view') || $can('comunicazione.view'))
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $emailActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#emailSubmenu" role="button" aria-expanded="{{ $emailActive ? 'true' : 'false' }}">
                        <span><i class="bi bi-envelope me-2"></i>Email &amp; Notifiche</span>
                        <i class="bi bi-chevron-down small"></i>
                    </a>
                    <div class="collapse {{ $emailActive ? 'show' : '' }}" id="emailSubmenu">
                        <ul class="nav flex-column ms-3">
                            @if ($can('email.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('comunicazioni.*') ? 'active' : '' }}" href="{{ $nav('comunicazioni.index') }}"><i class="bi bi-send me-2"></i>Invia Comunicazione</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-templates.*') ? 'active' : '' }}" href="{{ $nav('email-templates.index') }}"><i class="bi bi-palette me-2"></i>Template Email</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-coda.*') ? 'active' : '' }}" href="{{ $nav('email-coda.index') }}"><i class="bi bi-hourglass-split me-2"></i>Coda Invio</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-log.*') ? 'active' : '' }}" href="{{ $nav('email-log.index') }}"><i class="bi bi-clock-history me-2"></i>Storico Email</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-impostazioni.*') ? 'active' : '' }}" href="{{ $nav('email-impostazioni.index') }}"><i class="bi bi-gear me-2"></i>Impostazioni SMTP</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('email-anteprime.*') ? 'active' : '' }}" href="{{ $nav('email-anteprime.index') }}"><i class="bi bi-eye me-2"></i>Anteprime email</a></li>
                            @endif
                            @if ($can('comunicazione.view'))
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('annunci.*') ? 'active' : '' }}" href="{{ $nav('annunci.index') }}"><i class="bi bi-megaphone me-2"></i>Annunci</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('newsletter.*') ? 'active' : '' }}" href="{{ $nav('newsletter.index') }}"><i class="bi bi-envelope-paper me-2"></i>Newsletter</a></li>
                            <li class="nav-item"><a class="nav-link py-1 {{ request()->routeIs('sondaggi.*') ? 'active' : '' }}" href="{{ $nav('sondaggi.index') }}"><i class="bi bi-bar-chart me-2"></i>Sondaggi</a></li>
                            @endif
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
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('aggiornamenti.*') ? 'active' : '' }}" href="{{ $nav('aggiornamenti.index') }}">
                    <i class="bi bi-arrow-repeat me-2"></i>
                    Aggiornamenti
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('impostazioni-sistema.*') ? 'active' : '' }}" href="{{ $nav('impostazioni-sistema.index') }}">
                    <i class="bi bi-gear-wide-connected me-2"></i>
                    Impostazioni di sistema
                </a>
            </li>
            @endif
            @if ($sidebarHasAssoc && $can('struttura.view'))
            @if ($sidebarHasAssoc && ($isSuperAdmin || $utente?->isAdminAssociazione()))
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('ruoli.*') ? 'active' : '' }}" href="{{ $nav('ruoli.index') }}">
                    <i class="bi bi-shield-check me-2"></i>
                    Ruoli e permessi
                </a>
            </li>
            @endif
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
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('registro-modifiche.*') ? 'active' : '' }}" href="{{ $nav('registro-modifiche.index') }}">
                    <i class="bi bi-clock-history me-2"></i>
                    Registro modifiche
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('audit-accessi.*') ? 'active' : '' }}" href="{{ $nav('audit-accessi.index') }}">
                    <i class="bi bi-shield-lock me-2"></i>
                    Audit accessi
                </a>
            </li>
            @endif
            @if ($sidebarHasAssoc && $can('soci.view'))
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('soci-duplicati.*') ? 'active' : '' }}" href="{{ $nav('soci-duplicati.index') }}">
                    <i class="bi bi-people-fill me-2"></i>
                    Duplicati
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('export-import.*') ? 'active' : '' }}" href="{{ $nav('export-import.index') }}">
                    <i class="bi bi-arrow-down-up me-2"></i>
                    Export/Import
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('cestino.*') ? 'active' : '' }}" href="{{ $nav('cestino.index') }}">
                    <i class="bi bi-trash3 me-2"></i>
                    Cestino
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
