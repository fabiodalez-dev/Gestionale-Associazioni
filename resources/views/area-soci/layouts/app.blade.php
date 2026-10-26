<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        /** @var \App\Models\Socio $socioLayout */
        $socioLayout = auth('socio')->user();
        $associazione = $socioLayout?->associazione;
        $inizialiAssoc = mb_strtoupper(collect(preg_split('/\s+/', trim((string) $associazione?->nome)) ?: [])->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('')) ?: 'A';
        $inizialiSocio = mb_strtoupper(mb_substr((string) $socioLayout?->nome, 0, 1).mb_substr((string) $socioLayout?->cognome, 0, 1));
        $voce = fn (string $rotta, string $attiva, string $icona, string $etichetta) => \Illuminate\Support\Facades\Route::has($rotta)
            ? ['url' => route($rotta), 'attiva' => request()->routeIs($attiva), 'icona' => $icona, 'etichetta' => $etichetta]
            : null;
        $gruppi = [
            'La mia iscrizione' => array_filter([
                $voce('area-soci.dashboard', 'area-soci.dashboard', 'bi-grid-1x2', 'Panoramica'),
                $voce('area-soci.tessere', 'area-soci.tessere*', 'bi-credit-card-2-front', 'Le mie tessere'),
                $voce('area-soci.quote', 'area-soci.quote*', 'bi-wallet2', 'Le mie quote'),
                $voce('area-soci.profilo', 'area-soci.profilo*', 'bi-person', 'Il mio profilo'),
                $voce('area-soci.modifica-dati', 'area-soci.modifica-dati*', 'bi-pencil-square', 'Modifica i miei dati'),
            ]),
            'Vita associativa' => array_filter([
                $voce('area-soci.bacheca', 'area-soci.bacheca', 'bi-megaphone', 'Bacheca'),
                $voce('area-soci.assemblee', 'area-soci.assemblee*', 'bi-people', 'Assemblee'),
                $voce('area-soci.proposte', 'area-soci.proposte*', 'bi-lightbulb', 'Proposte'),
                // Solo per chi ricopre una carica (issue #11)
                $socioLayout?->cariche()->attive()->exists()
                    ? $voce('area-soci.dichiarazione-interessi', 'area-soci.dichiarazione-interessi*', 'bi-shield-check', 'Conflitti di interesse')
                    : null,
                $voce('area-soci.sondaggi', 'area-soci.sondaggi*', 'bi-bar-chart', 'Sondaggi'),
                $voce('area-soci.volontariato', 'area-soci.volontariato*', 'bi-person-raised-hand', 'Volontariato'),
            ]),
        ];
    @endphp
    <title>@yield('title', 'Area riservata - '.($socioLayout?->nome_completo ?? 'Socio'))</title>
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    @if ($associazione)
        {{-- Colore dell'associazione anche nell'area riservata dei soci --}}
        @include('partials.stile-associazione', ['associazione' => $associazione])
    @else
        <meta name="theme-color" content="#0D9488">
    @endif
    @include('partials.pwa')
    @stack('styles')
</head>
<body class="area-soci">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="app-shell">
        <nav class="sidebar" id="mainSidebar" aria-label="Navigazione area soci">
            <div class="sidebar-inner">
                <a class="sidebar-brand" href="{{ route('area-soci.dashboard') }}">
                    @if ($associazione?->logoUrl())
                        <span class="brand-mark brand-logo"><img src="{{ $associazione->logoUrl('miniatura') }}" alt=""></span>
                    @else
                        <span class="brand-mark">{{ mb_substr($inizialiAssoc, 0, 1) }}</span>
                    @endif
                    <span class="brand-name">{{ $associazione?->nome ?? 'Area soci' }}</span>
                </a>

                <div class="tenant-card">
                    <span class="tenant-ini">{{ $inizialiSocio }}</span>
                    <span class="tenant-meta">
                        <span class="tenant-kicker d-block">Area riservata</span>
                        <span class="tenant-name d-block">{{ $socioLayout?->nome_completo }}</span>
                    </span>
                </div>

                @foreach ($gruppi as $titolo => $voci)
                    @continue($voci === [])
                    <p class="sidebar-heading">{{ $titolo }}</p>
                    <ul class="nav flex-column">
                        @foreach ($voci as $v)
                            <li class="nav-item">
                                <a class="nav-link {{ $v['attiva'] ? 'active' : '' }}" href="{{ $v['url'] }}" @if ($v['attiva']) aria-current="page" @endif>
                                    <i class="bi {{ $v['icona'] }}"></i>{{ $v['etichetta'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach

                <div class="sidebar-footer">
                    <div class="plan-card">
                        <div class="plan-row"><span class="plan-title">Socio n. {{ $socioLayout?->numero_socio }}</span><span class="plan-count">{{ $socioLayout?->stato }}</span></div>
                        <div class="plan-note mt-1">Iscritto dal {{ $socioLayout?->data_iscrizione?->format('d/m/Y') ?? '—' }}</div>
                    </div>
                </div>
            </div>
        </nav>

        <div class="app-main">
            <header class="topbar">
                <div class="topbar-inner">
                    <button type="button" class="topbar-btn topbar-menu-btn" id="sidebarToggle" aria-label="Apri il menu">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="topbar-title">
                        <i class="bi bi-shield-check"></i>
                        <span>Area riservata ai soci{{ $associazione ? ' di '.$associazione->nome : '' }}</span>
                    </div>
                    <span class="topbar-sep"></span>
                    <button type="button" class="topbar-btn theme-toggle-icon" id="themeToggle" aria-label="Cambia tema chiaro/scuro" title="Tema chiaro/scuro">
                        <i class="bi bi-moon theme-label-light"></i>
                        <i class="bi bi-sun theme-label-dark"></i>
                    </button>
                    <div class="dropdown">
                        <button type="button" class="topbar-user" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu utente">
                            <span class="avatar">{{ $inizialiSocio }}</span>
                            <span class="who">
                                <span class="who-name d-block">{{ $socioLayout?->nome_completo }}</span>
                                <span class="who-role d-block">Socio</span>
                            </span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <div class="px-2 pt-1 pb-2">
                                <div class="fw-semibold text-body">{{ $socioLayout?->nome_completo }}</div>
                                <div class="small text-muted text-truncate">{{ $socioLayout?->email }}</div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="{{ route('area-soci.profilo') }}"><i class="bi bi-person"></i>Il mio profilo</a>
                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ route('area-soci.logout') }}" class="m-0">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i>Esci</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="main-content" id="mainContent">
                <div class="page-container" data-page="{{ \Illuminate\Support\Str::slug(request()->route()?->getName() ?? 'area-soci') }}">
                    @include('partials.flash')
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/main.js') }}"></script>
    @stack('scripts')
</body>
</html>
