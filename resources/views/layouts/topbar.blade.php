@php
    /** @var \App\Models\Utente $utente */
    $utente = auth()->user();
    $ruoli = ['super_admin' => 'Super admin', 'admin_associazione' => 'Amministratore', 'operatore' => 'Operatore'];
    $tenantAttivo = filled(session('associazione_id')) || (! $utente->isSuperAdmin() && filled($utente->associazione_id));

    // Cose da gestire: richieste dal sito pubblico in attesa
    $daGestire = collect();
    if ($tenantAttivo) {
        if (\Illuminate\Support\Facades\Route::has('richieste-iscrizione.index') && ($utente->isSuperAdmin() || $utente->can('iscrizioni.view'))) {
            $n = \App\Models\RichiestaIscrizione::query()->inAttesa()->count();
            if ($n > 0) {
                $daGestire->push(['n' => $n, 'testo' => $n === 1 ? 'richiesta di iscrizione da approvare' : 'richieste di iscrizione da approvare', 'icona' => 'bi-person-plus', 'tono' => 'tone-2', 'url' => route('richieste-iscrizione.index')]);
            }
        }
        if (\Illuminate\Support\Facades\Route::has('richieste-modifica-dati.index') && ($utente->isSuperAdmin() || $utente->can('soci.view'))) {
            $n = \App\Models\RichiestaModificaDati::query()->inAttesa()->count();
            if ($n > 0) {
                $daGestire->push(['n' => $n, 'testo' => $n === 1 ? 'modifica dati da verificare' : 'modifiche dati da verificare', 'icona' => 'bi-person-lines-fill', 'tono' => 'tone-1', 'url' => route('richieste-modifica-dati.index')]);
            }
        }
        if (\Illuminate\Support\Facades\Route::has('tessere.index') && ($utente->isSuperAdmin() || $utente->can('tessere.view'))) {
            $n = \App\Models\Tessera::query()->where('stato', 'Attiva')->whereBetween('data_scadenza', [now()->startOfDay(), now()->addDays(30)->endOfDay()])->count();
            if ($n > 0) {
                $daGestire->push(['n' => $n, 'testo' => $n === 1 ? 'tessera in scadenza entro 30 giorni' : 'tessere in scadenza entro 30 giorni', 'icona' => 'bi-hourglass-split', 'tono' => 'tone-3', 'url' => route('campagna-rinnovi.index')]);
            }
        }
    }
    if ($utente->isSuperAdmin()) {
        $n = \App\Models\RichiestaRegistrazione::query()->daEsaminare()->count();
        if ($n > 0) {
            $daGestire->prepend(['n' => $n, 'testo' => $n === 1 ? 'associazione chiede di iscriversi' : 'associazioni chiedono di iscriversi', 'icona' => 'bi-buildings', 'tono' => 'tone-0', 'url' => route('richieste-registrazione.index')]);
        }
    }
    $totaleDaGestire = $daGestire->sum('n');
    $inizialiUtente = mb_strtoupper(mb_substr((string) $utente->nome, 0, 1).mb_substr((string) $utente->cognome, 0, 1)) ?: 'U';
@endphp
<header class="topbar">
    <div class="topbar-inner">
        <button type="button" class="topbar-btn topbar-menu-btn" id="sidebarToggle" aria-label="Apri il menu">
            <i class="bi bi-list"></i>
        </button>
        <button type="button" class="topbar-search" id="globalSearchBtn" aria-label="Ricerca globale" title="Ricerca globale (Ctrl+K / ⌘K)">
            <i class="bi bi-search"></i>
            <span>Cerca soci, tessere, eventi…</span>
            <kbd>⌘K</kbd>
        </button>
        <span class="topbar-sep"></span>
        <button type="button" class="topbar-btn theme-toggle-icon" id="themeToggle" aria-label="Cambia tema chiaro/scuro" title="Tema chiaro/scuro">
            <i class="bi bi-moon theme-label-light"></i>
            <i class="bi bi-sun theme-label-dark"></i>
        </button>
        <div class="dropdown">
            <button type="button" class="topbar-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Da gestire{{ $totaleDaGestire > 0 ? ': '.$totaleDaGestire : '' }}">
                <i class="bi bi-bell"></i>
                @if ($totaleDaGestire > 0)
                    <span class="dot">{{ $totaleDaGestire > 99 ? '99+' : $totaleDaGestire }}</span>
                @endif
            </button>
            <div class="dropdown-menu dropdown-menu-end notify-menu">
                <div class="notify-head">Da gestire</div>
                @forelse ($daGestire as $voce)
                    <a class="notify-item" href="{{ $voce['url'] }}">
                        <span class="icon-tile icon-tile-sm {{ $voce['tono'] }}"><i class="bi {{ $voce['icona'] }}"></i></span>
                        <span><b>{{ $voce['n'] }}</b> {{ $voce['testo'] }}</span>
                    </a>
                @empty
                    <div class="notify-empty"><i class="bi bi-check2-circle"></i>Niente in sospeso</div>
                @endforelse
            </div>
        </div>
        <div class="dropdown">
            <button type="button" class="topbar-user" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu utente">
                <span class="avatar">{{ $inizialiUtente }}</span>
                <span class="who">
                    <span class="who-name d-block">{{ $utente->nome_completo ?: 'Utente' }}</span>
                    <span class="who-role d-block">{{ $ruoli[$utente->ruolo] ?? \Illuminate\Support\Str::headline((string) $utente->ruolo) }}</span>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                <div class="px-2 pt-1 pb-2">
                    <div class="fw-semibold text-body">{{ $utente->nome_completo }}</div>
                    <div class="small text-muted text-truncate">{{ $utente->email }}</div>
                </div>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="{{ route('profile.show') }}"><i class="bi bi-person-gear"></i>Profilo e sicurezza</a>
                @if (\Illuminate\Support\Facades\Route::has('configurazioni.index') && $tenantAttivo && ($utente->isSuperAdmin() || $utente->can('struttura.view')))
                    <a class="dropdown-item" href="{{ route('configurazioni.index') }}"><i class="bi bi-gear"></i>Impostazioni associazione</a>
                @endif
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i>Esci</button>
                </form>
            </div>
        </div>
    </div>
</header>
