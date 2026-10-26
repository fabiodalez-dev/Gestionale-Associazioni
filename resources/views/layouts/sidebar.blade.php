@php
    /** @var \App\Models\Utente $utente */
    $utente = auth()->user();
    $isSuperAdmin = $utente?->isSuperAdmin() ?? false;
    // Per admin_associazione/operatore il tenant è quello dell'utente anche se la sessione non è ancora popolata
    $sidebarHasAssoc = filled(session('associazione_id')) || (! $isSuperAdmin && filled($utente?->associazione_id));
    $associazioneNome = session('associazione_nome') ?? (! $isSuperAdmin ? $utente?->associazione?->nome : null);
    $associazioneCorrente = $sidebarHasAssoc
        ? \App\Models\Associazione::query()->find(session('associazione_id') ?: $utente?->associazione_id)
        : null;
    $appNome = session('app_name', config('app.name', 'Gestione Associazioni'));
    $iniziali = fn (?string $testo) => mb_strtoupper(collect(preg_split('/\s+/', trim((string) $testo)) ?: [])->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('')) ?: '?';

    // Le rotte dei moduli vengono aggiunte progressivamente: i link a moduli
    // non ancora migrati restano disabilitati (#) senza rompere la sidebar.
    $nav = fn (string $name, array $params = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $params) : '#';
    $can = fn (string $permission) => $isSuperAdmin || ($utente?->can($permission) ?? false);
    $isAdmin = $isSuperAdmin || ($utente?->isAdminAssociazione() ?? false);

    $emailActive = request()->routeIs('comunicazioni.*', 'email-templates.*', 'email-coda.*', 'email-log.*', 'email-impostazioni.*', 'email-anteprime.*', 'annunci.*', 'newsletter.*', 'sondaggi.*');
    $apiActive = request()->routeIs('api-keys.*', 'api-docs.*');
    $registroActive = request()->routeIs('libro-soci.*', 'cessazioni.*', 'organi-sociali.*');
    $economiaActive = request()->routeIs('prima-nota.*', 'report-economico.*', 'donazioni.*');
    // Piano "Organizzazione 3": volontari/turni, spazi, inventario, progetti, riunioni e decisioni
    $organizzazioneActive = request()->routeIs('turni.*', 'volontari.*', 'spazi.*', 'attrezzature.*', 'prestiti.*', 'progetti.*', 'riunioni.*', 'decisioni.*', 'elezioni.*', 'proposte.*', 'conflitti.*');
    // Piano "Organizzazione 3": mini-CRM, sponsor e referral
    $relazioniActive = request()->routeIs('contatti-esterni.*', 'sponsor.*', 'ambasciatori.*');
    $configurazioneActive = request()->routeIs('config-campi.*', 'config-tags.*', 'categorie-socio.*', 'tipi-socio.*', 'config-gruppi.*')
        || (request()->routeIs('configurazioni.*') && request('section') === 'sedi');
    $datiActive = request()->routeIs('registro-modifiche.*', 'audit-accessi.*', 'soci-duplicati.*', 'export-import.*', 'cestino.*');

    $sediPerimetro = \App\Support\PerimetroSedi::sedi();
@endphp
<nav class="sidebar" id="mainSidebar" aria-label="Navigazione principale">
    <div class="sidebar-inner">
        <a class="sidebar-brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">{{ mb_strtoupper(mb_substr($appNome, 0, 1)) }}</span>
            <span class="brand-name">{{ $appNome }}</span>
        </a>

        {{-- Associazione corrente; il super admin la cambia dal menu a tendina --}}
        @if ($isSuperAdmin)
            @php
                $sidebarAssocList = \App\Models\Associazione::query()
                    ->where('attiva', true)
                    ->orderBy('nome')
                    ->get(['id', 'nome', 'citta']);
            @endphp
            <div class="dropdown tenant-switch">
                <button type="button" class="tenant-card w-100 text-start" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Cambia associazione">
                    <span class="tenant-ini">
                        @if ($associazioneCorrente?->logoUrl())
                            <img src="{{ $associazioneCorrente->logoUrl() }}" alt="">
                        @elseif ($associazioneCorrente)
                            {{ $iniziali($associazioneCorrente->nome) }}
                        @else
                            <i class="bi bi-globe2"></i>
                        @endif
                    </span>
                    <span class="tenant-meta">
                        <span class="tenant-kicker d-block">{{ $associazioneCorrente ? 'Associazione' : 'Super admin' }}</span>
                        <span class="tenant-name d-block">{{ $associazioneCorrente?->nome ?? 'Tutte le associazioni' }}</span>
                    </span>
                    <i class="bi bi-chevron-expand"></i>
                </button>
                <div class="dropdown-menu tenant-menu">
                    @if ($sidebarAssocList->count() > 6)
                        <div class="search-field tenant-search">
                            <i class="bi bi-search"></i>
                            <input type="search" class="form-control form-control-sm" placeholder="Cerca associazione…" aria-label="Cerca associazione" data-tenant-filter>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('tenant.switch') }}" class="tenant-list">
                        @csrf
                        <button type="submit" name="associazione_id" value="" class="tenant-option {{ $associazioneCorrente ? '' : 'active' }}">
                            <span class="avatar avatar-sm avatar-square tone-4"><i class="bi bi-globe2"></i></span>
                            <span class="tenant-option-text"><b>Vista globale</b><small>Tutte le associazioni</small></span>
                            @unless ($associazioneCorrente)<i class="bi bi-check2"></i>@endunless
                        </button>
                        @foreach ($sidebarAssocList as $sa)
                            @php $selezionata = (string) $associazioneCorrente?->id === (string) $sa->id; @endphp
                            <button type="submit" name="associazione_id" value="{{ $sa->id }}" class="tenant-option {{ $selezionata ? 'active' : '' }}" data-nome="{{ mb_strtolower($sa->nome.' '.$sa->citta) }}">
                                <span class="avatar avatar-sm avatar-square tone-{{ $loop->index % 6 }}">{{ $iniziali($sa->nome) }}</span>
                                <span class="tenant-option-text"><b>{{ $sa->nome }}</b>@if ($sa->citta)<small>{{ $sa->citta }}</small>@endif</span>
                                @if ($selezionata)<i class="bi bi-check2"></i>@endif
                            </button>
                        @endforeach
                        <p class="tenant-empty" hidden>Nessuna associazione trovata</p>
                    </form>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="{{ $nav('associazioni.index') }}"><i class="bi bi-buildings"></i>Gestisci associazioni</a>
                </div>
            </div>
        @elseif ($associazioneCorrente)
            <div class="tenant-card">
                <span class="tenant-ini">
                    @if ($associazioneCorrente->logoUrl())
                        <img src="{{ $associazioneCorrente->logoUrl() }}" alt="">
                    @else
                        {{ $iniziali($associazioneCorrente->nome) }}
                    @endif
                </span>
                <span class="tenant-meta">
                    <span class="tenant-kicker d-block">Associazione</span>
                    <span class="tenant-name d-block">{{ $associazioneCorrente->nome }}</span>
                </span>
            </div>
        @endif
        @if ($sediPerimetro !== null)
            <div class="tenant-perimetro" title="Vedi e gestisci solo i dati di queste sedi">
                <i class="bi bi-geo-alt me-1"></i>{{ \App\Models\Sede::query()->orderBy('nome')->pluck('nome')->join(', ') }}
            </div>
        @endif

        @if ($sidebarHasAssoc)
            <p class="sidebar-heading">Principale</p>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                        <i class="bi bi-grid-1x2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('attivita-admin.*') ? 'active' : '' }}" href="{{ $nav('attivita-admin.index') }}">
                        <i class="bi bi-check2-square"></i>Attività
                    </a>
                </li>
                @if ($can('scadenze.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('scadenzario.*') ? 'active' : '' }}" href="{{ $nav('scadenzario.index') }}">
                        <i class="bi bi-calendar-check"></i>Scadenzario
                    </a>
                </li>
                @endif
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('statistiche-annuali.*') ? 'active' : '' }}" href="{{ $nav('statistiche-annuali.index') }}">
                        <i class="bi bi-graph-up-arrow"></i>Statistiche annuali
                    </a>
                </li>
            </ul>

            @if ($can('soci.view') || $can('tessere.view') || $can('quote.view'))
            <p class="sidebar-heading">Soci</p>
            <ul class="nav flex-column">
                @if ($can('soci.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('soci.*') ? 'active' : '' }}" href="{{ $nav('soci.index') }}">
                        <i class="bi bi-people"></i>Soci
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
                        <i class="bi bi-person-plus"></i>Richieste iscrizione
                        @if ($richiesteIscrizionePendenti > 0)
                            <span class="badge bg-danger ms-1" title="Richieste di iscrizione da approvare">{{ $richiesteIscrizionePendenti }}</span>
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
                        <i class="bi bi-person-lines-fill"></i>Richieste modifiche
                        @if ($richiesteModifichePendenti > 0)
                            <span class="badge bg-warning ms-1" title="Richieste di modifica dati da gestire">{{ $richiesteModifichePendenti }}</span>
                        @endif
                    </a>
                </li>
                @endif
                @if ($can('tessere.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('tessere.*') ? 'active' : '' }}" href="{{ $nav('tessere.index') }}">
                        <i class="bi bi-credit-card-2-front"></i>Tessere
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('campagna-rinnovi.*') ? 'active' : '' }}" href="{{ $nav('campagna-rinnovi.index') }}">
                        <i class="bi bi-arrow-repeat"></i>Campagna rinnovi
                    </a>
                </li>
                @endif
                @if ($can('quote.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('quote.*') ? 'active' : '' }}" href="{{ $nav('quote.index') }}">
                        <i class="bi bi-wallet2"></i>Quote e pagamenti
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('apertura-anno.*') ? 'active' : '' }}" href="{{ $nav('apertura-anno.index') }}">
                        <i class="bi bi-calendar-plus"></i>Apri anno sociale
                    </a>
                </li>
                @endif
                @if ($can('soci.view'))
                <li class="nav-item">
                    <a class="nav-link {{ $registroActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#registroSubmenu" role="button" aria-expanded="{{ $registroActive ? 'true' : 'false' }}">
                        <i class="bi bi-journal-bookmark"></i>Registro
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <div class="collapse {{ $registroActive ? 'show' : '' }}" id="registroSubmenu">
                        <ul class="nav flex-column">
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('libro-soci.*') ? 'active' : '' }}" href="{{ $nav('libro-soci.index') }}"><i class="bi bi-book"></i>Libro soci</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('cessazioni.*') ? 'active' : '' }}" href="{{ $nav('cessazioni.index') }}"><i class="bi bi-person-dash"></i>Cessazioni</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('organi-sociali.*') ? 'active' : '' }}" href="{{ $nav('organi-sociali.index') }}"><i class="bi bi-diagram-3"></i>Organi sociali</a></li>
                        </ul>
                    </div>
                </li>
                @endif
            </ul>
            @endif

            <p class="sidebar-heading">Attività</p>
            <ul class="nav flex-column">
                @if ($can('eventi.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('eventi.*') ? 'active' : '' }}" href="{{ $nav('eventi.index') }}">
                        <i class="bi bi-calendar-event"></i>Eventi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('calendario-eventi.*') ? 'active' : '' }}" href="{{ $nav('calendario-eventi.index') }}">
                        <i class="bi bi-calendar3"></i>Calendario
                    </a>
                </li>
                @endif
                @if ($can('economia.view'))
                <li class="nav-item">
                    <a class="nav-link {{ $economiaActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#economiaSubmenu" role="button" aria-expanded="{{ $economiaActive ? 'true' : 'false' }}">
                        <i class="bi bi-cash-coin"></i>Economia
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <div class="collapse {{ $economiaActive ? 'show' : '' }}" id="economiaSubmenu">
                        <ul class="nav flex-column">
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('prima-nota.*') ? 'active' : '' }}" href="{{ $nav('prima-nota.index') }}"><i class="bi bi-journal-text"></i>Prima nota</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('report-economico.*') ? 'active' : '' }}" href="{{ $nav('report-economico.index') }}"><i class="bi bi-graph-up"></i>Report economico</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('donazioni.*') ? 'active' : '' }}" href="{{ $nav('donazioni.index') }}"><i class="bi bi-heart"></i>Donazioni</a></li>
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('volontari.view') || $can('spazi.view') || $can('progetti.view') || $can('riunioni.view') || $can('proposte.view'))
                <li class="nav-item">
                    <a class="nav-link {{ $organizzazioneActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#organizzazioneSubmenu" role="button" aria-expanded="{{ $organizzazioneActive ? 'true' : 'false' }}">
                        <i class="bi bi-diagram-3"></i>Organizzazione
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <div class="collapse {{ $organizzazioneActive ? 'show' : '' }}" id="organizzazioneSubmenu">
                        <ul class="nav flex-column">
                            @if ($can('volontari.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('turni.*', 'volontari.*') ? 'active' : '' }}" href="{{ $nav('turni.index') }}"><i class="bi bi-person-raised-hand"></i>Volontari e turni</a></li>
                            @endif
                            @if ($can('spazi.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('spazi.*') ? 'active' : '' }}" href="{{ $nav('spazi.index') }}"><i class="bi bi-door-open"></i>Spazi</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('attrezzature.*') ? 'active' : '' }}" href="{{ $nav('attrezzature.index') }}"><i class="bi bi-box-seam"></i>Inventario</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('prestiti.*') ? 'active' : '' }}" href="{{ $nav('prestiti.index') }}"><i class="bi bi-arrow-left-right"></i>Prestiti</a></li>
                            @endif
                            @if ($can('progetti.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('progetti.*') ? 'active' : '' }}" href="{{ $nav('progetti.index') }}"><i class="bi bi-kanban"></i>Progetti</a></li>
                            @endif
                            @if ($can('riunioni.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('riunioni.*', 'elezioni.*') ? 'active' : '' }}" href="{{ $nav('riunioni.index') }}"><i class="bi bi-people"></i>Riunioni e assemblee</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('decisioni.*') ? 'active' : '' }}" href="{{ $nav('decisioni.index') }}"><i class="bi bi-check2-circle"></i>Decisioni</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('conflitti.*') ? 'active' : '' }}" href="{{ $nav('conflitti.index') }}"><i class="bi bi-shield-exclamation"></i>Conflitti di interesse</a></li>
                            @endif
                            @if ($can('proposte.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('proposte.*') ? 'active' : '' }}" href="{{ $nav('proposte.index') }}"><i class="bi bi-lightbulb"></i>Proposte dei soci</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('documenti.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('documenti.*') ? 'active' : '' }}" href="{{ $nav('documenti.index') }}">
                        <i class="bi bi-folder2-open"></i>Documenti
                    </a>
                </li>
                @endif
                @if ($can('manuale.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('manuale.*') ? 'active' : '' }}" href="{{ $nav('manuale.index') }}">
                        <i class="bi bi-book"></i>Manuale operativo
                    </a>
                </li>
                @endif
            </ul>

            @if ($can('relazioni.view') || $can('soci.view') || $can('email.view') || $can('comunicazione.view'))
            <p class="sidebar-heading">Relazioni</p>
            <ul class="nav flex-column">
                @if ($can('email.view') || $can('comunicazione.view'))
                <li class="nav-item">
                    <a class="nav-link {{ $emailActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#emailSubmenu" role="button" aria-expanded="{{ $emailActive ? 'true' : 'false' }}">
                        <i class="bi bi-envelope"></i>Email &amp; Notifiche
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <div class="collapse {{ $emailActive ? 'show' : '' }}" id="emailSubmenu">
                        <ul class="nav flex-column">
                            @if ($can('email.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('comunicazioni.*') ? 'active' : '' }}" href="{{ $nav('comunicazioni.index') }}"><i class="bi bi-send"></i>Invia Comunicazione</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('email-templates.*') ? 'active' : '' }}" href="{{ $nav('email-templates.index') }}"><i class="bi bi-palette"></i>Template Email</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('email-coda.*') ? 'active' : '' }}" href="{{ $nav('email-coda.index') }}"><i class="bi bi-hourglass-split"></i>Coda Invio</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('email-log.*') ? 'active' : '' }}" href="{{ $nav('email-log.index') }}"><i class="bi bi-clock-history"></i>Storico Email</a></li>
                            @if ($isAdmin)
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('email-impostazioni.*') ? 'active' : '' }}" href="{{ $nav('email-impostazioni.index') }}"><i class="bi bi-gear"></i>Impostazioni SMTP</a></li>
                            @endif
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('email-anteprime.*') ? 'active' : '' }}" href="{{ $nav('email-anteprime.index') }}"><i class="bi bi-eye"></i>Anteprime email</a></li>
                            @endif
                            @if ($can('comunicazione.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('annunci.*') ? 'active' : '' }}" href="{{ $nav('annunci.index') }}"><i class="bi bi-megaphone"></i>Annunci</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('newsletter.*') ? 'active' : '' }}" href="{{ $nav('newsletter.index') }}"><i class="bi bi-envelope-paper"></i>Newsletter</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('sondaggi.*') ? 'active' : '' }}" href="{{ $nav('sondaggi.index') }}"><i class="bi bi-bar-chart"></i>Sondaggi</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                @endif
                @if ($can('relazioni.view'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('crm.*') ? 'active' : '' }}" href="{{ $nav('crm.index') }}">
                        <i class="bi bi-kanban"></i>CRM a colonne
                    </a>
                </li>
                @endif
                @if ($can('relazioni.view') || $can('soci.view'))
                <li class="nav-item">
                    <a class="nav-link {{ $relazioniActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#relazioniSubmenu" role="button" aria-expanded="{{ $relazioniActive ? 'true' : 'false' }}">
                        <i class="bi bi-person-rolodex"></i>Contatti e sponsor
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <div class="collapse {{ $relazioniActive ? 'show' : '' }}" id="relazioniSubmenu">
                        <ul class="nav flex-column">
                            @if ($can('relazioni.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('contatti-esterni.*') ? 'active' : '' }}" href="{{ $nav('contatti-esterni.index') }}"><i class="bi bi-person-rolodex"></i>Contatti esterni</a></li>
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('sponsor.*') ? 'active' : '' }}" href="{{ $nav('sponsor.index') }}"><i class="bi bi-award"></i>Sponsor</a></li>
                            @endif
                            @if ($can('soci.view'))
                            <li class="nav-item"><a class="nav-link {{ request()->routeIs('ambasciatori.*') ? 'active' : '' }}" href="{{ $nav('ambasciatori.index') }}"><i class="bi bi-megaphone"></i>Ambasciatori</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                @endif
            </ul>
            @endif
        @elseif ($isSuperAdmin)
            <div class="tenant-perimetro mt-3">
                <i class="bi bi-info-circle me-1"></i>Scegli un'associazione dal selettore qui sopra per vederne il menu.
            </div>
        @endif

        <p class="sidebar-heading">Amministrazione</p>
        <ul class="nav flex-column">
            @if ($isSuperAdmin)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('associazioni.*') ? 'active' : '' }}" href="{{ $nav('associazioni.index') }}">
                    <i class="bi bi-buildings"></i>Associazioni
                </a>
            </li>
            @php
                $richiesteRegistrazione = \App\Models\RichiestaRegistrazione::query()->daEsaminare()->count();
            @endphp
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('richieste-registrazione.*') ? 'active' : '' }}" href="{{ $nav('richieste-registrazione.index') }}">
                    <i class="bi bi-inbox"></i>Richieste di iscrizione
                    @if ($richiesteRegistrazione > 0)
                        <span class="badge bg-danger ms-1" title="Associazioni in attesa di approvazione">{{ $richiesteRegistrazione }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('impostazioni-sistema.*') ? 'active' : '' }}" href="{{ $nav('impostazioni-sistema.index') }}">
                    <i class="bi bi-sliders"></i>Impostazioni di sistema
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('aggiornamenti.*') ? 'active' : '' }}" href="{{ $nav('aggiornamenti.index') }}">
                    <i class="bi bi-cloud-arrow-down"></i>Aggiornamenti
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('job-falliti.*') ? 'active' : '' }}" href="{{ $nav('job-falliti.index') }}">
                    <i class="bi bi-exclamation-octagon"></i>Job falliti
                </a>
            </li>
            @endif
            @if ($sidebarHasAssoc && $can('struttura.view'))
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('configurazioni.*') && request('section') !== 'sedi' ? 'active' : '' }}" href="{{ $nav('configurazioni.index') }}">
                    <i class="bi bi-gear"></i>Impostazioni
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('amministratori.*') ? 'active' : '' }}" href="{{ $nav('amministratori.index') }}">
                    <i class="bi bi-person-badge"></i>Utenti
                </a>
            </li>
            @if ($isAdmin)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('ruoli.*') ? 'active' : '' }}" href="{{ $nav('ruoli.index') }}">
                    <i class="bi bi-shield-check"></i>Ruoli e permessi
                </a>
            </li>
            @endif
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
                    <i class="bi bi-person-vcard"></i>Account frontend
                    @if ($richiesteSocioPendenti > 0)
                        <span class="badge bg-warning ms-1" title="Richieste socio pendenti">{{ $richiesteSocioPendenti }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $configurazioneActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#configurazioneSubmenu" role="button" aria-expanded="{{ $configurazioneActive ? 'true' : 'false' }}">
                    <i class="bi bi-ui-checks-grid"></i>Configurazione soci
                    <i class="bi bi-chevron-down"></i>
                </a>
                <div class="collapse {{ $configurazioneActive ? 'show' : '' }}" id="configurazioneSubmenu">
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('configurazioni.*') && request('section') === 'sedi' ? 'active' : '' }}" href="{{ $nav('configurazioni.index', ['section' => 'sedi']) }}"><i class="bi bi-geo-alt"></i>Sedi</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('categorie-socio.*') ? 'active' : '' }}" href="{{ $nav('categorie-socio.index') }}"><i class="bi bi-bookmark-star"></i>Categorie Socio</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('tipi-socio.*') ? 'active' : '' }}" href="{{ $nav('tipi-socio.index') }}"><i class="bi bi-person-badge"></i>Tipi Socio</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('config-campi.*') ? 'active' : '' }}" href="{{ $nav('config-campi.index') }}"><i class="bi bi-journal-plus"></i>Campi Personalizzati</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('config-tags.*') ? 'active' : '' }}" href="{{ $nav('config-tags.index') }}"><i class="bi bi-tags"></i>Gestione Tag</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('config-gruppi.*') ? 'active' : '' }}" href="{{ $nav('config-gruppi.index') }}"><i class="bi bi-collection"></i>Gruppi Dinamici</a></li>
                    </ul>
                </div>
            </li>
            @endif
            @if ($sidebarHasAssoc && ($can('struttura.view') || $can('soci.view')))
            <li class="nav-item">
                <a class="nav-link {{ $datiActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#datiSubmenu" role="button" aria-expanded="{{ $datiActive ? 'true' : 'false' }}">
                    <i class="bi bi-database"></i>Dati e sicurezza
                    <i class="bi bi-chevron-down"></i>
                </a>
                <div class="collapse {{ $datiActive ? 'show' : '' }}" id="datiSubmenu">
                    <ul class="nav flex-column">
                        @if ($can('struttura.view'))
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('registro-modifiche.*') ? 'active' : '' }}" href="{{ $nav('registro-modifiche.index') }}"><i class="bi bi-clock-history"></i>Registro modifiche</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('audit-accessi.*') ? 'active' : '' }}" href="{{ $nav('audit-accessi.index') }}"><i class="bi bi-shield-lock"></i>Audit accessi</a></li>
                        @endif
                        @if ($can('soci.view'))
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('soci-duplicati.*') ? 'active' : '' }}" href="{{ $nav('soci-duplicati.index') }}"><i class="bi bi-people-fill"></i>Duplicati</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('export-import.*') ? 'active' : '' }}" href="{{ $nav('export-import.index') }}"><i class="bi bi-arrow-down-up"></i>Export/Import</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('cestino.*') ? 'active' : '' }}" href="{{ $nav('cestino.index') }}"><i class="bi bi-trash3"></i>Cestino</a></li>
                        @endif
                    </ul>
                </div>
            </li>
            @endif
            @if (($sidebarHasAssoc || $isSuperAdmin) && $can('api.view'))
            <li class="nav-item">
                <a class="nav-link {{ $apiActive ? '' : 'collapsed' }}" data-bs-toggle="collapse" href="#apiSubmenu" role="button" aria-expanded="{{ $apiActive ? 'true' : 'false' }}">
                    <i class="bi bi-hdd-network"></i>API REST
                    <i class="bi bi-chevron-down"></i>
                </a>
                <div class="collapse {{ $apiActive ? 'show' : '' }}" id="apiSubmenu">
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('api-keys.*') ? 'active' : '' }}" href="{{ $nav('api-keys.index') }}"><i class="bi bi-key"></i>Chiavi API</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('api-docs.*') ? 'active' : '' }}" href="{{ $nav('api-docs.index') }}"><i class="bi bi-book"></i>Documentazione</a></li>
                    </ul>
                </div>
            </li>
            @endif
        </ul>

        @if ($associazioneCorrente && $can('soci.view'))
            @php
                $sociTotali = \App\Models\Socio::query()->count();
                $sociAttivi = \App\Models\Socio::query()->where('stato', 'Attivo')->count();
            @endphp
            <div class="sidebar-footer">
                <a class="plan-card d-block" href="{{ $nav('soci.index') }}">
                    <div class="plan-row">
                        <span class="plan-title">Soci attivi</span>
                        <span class="plan-count">{{ number_format($sociAttivi, 0, ',', '.') }} / {{ number_format($sociTotali, 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Soci attivi sul totale" aria-valuenow="{{ $sociAttivi }}" aria-valuemin="0" aria-valuemax="{{ max(1, $sociTotali) }}">
                        <div class="progress-bar" style="width: {{ $sociTotali > 0 ? round($sociAttivi * 100 / $sociTotali) : 0 }}%"></div>
                    </div>
                    <div class="plan-note">{{ $associazioneCorrente->nome }}</div>
                </a>
            </div>
        @endif
    </div>
</nav>
