{{-- Layout del frontend pubblico (/a/{slug}): brandizzato per tenant --}}
@php /** @var \App\Models\Associazione $associazione */ @endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $associazione->nome)</title>
    @if (filled($associazione->descrizione_pubblica))
        <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($associazione->descrizione_pubblica), 160) }}">
    @endif
    <script>
        // Applica il tema salvato prima del paint per evitare flash
        (function () {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        body.pubblico-body { background: var(--ds-bg); color: var(--ds-text); }
        .pubblico-navbar { background: var(--ds-surface); border-bottom: 1px solid var(--ds-border); }
        .pubblico-footer { background: var(--ds-surface); border-top: 1px solid var(--ds-border); }
        .pubblico-hero {
            background: linear-gradient(135deg, var(--ds-accent-subtle), var(--ds-surface));
            border: 1px solid var(--ds-border);
            border-radius: var(--ds-radius-lg);
        }
        .pubblico-logo { height: 36px; width: auto; object-fit: contain; }
        .evento-card { border-radius: var(--ds-radius-lg); overflow: hidden; transition: transform .15s var(--ds-ease), box-shadow .15s var(--ds-ease); }
        .evento-card:hover { transform: translateY(-2px); box-shadow: var(--ds-shadow-md); }
        .evento-card-img { height: 170px; object-fit: cover; width: 100%; }
        .evento-card-placeholder { height: 170px; background: var(--ds-accent-subtle); display: flex; align-items: center; justify-content: center; }
    </style>
    @stack('styles')
</head>
<body class="pubblico-body d-flex flex-column min-vh-100">

    <nav class="navbar navbar-expand-md pubblico-navbar">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('pubblico.home.show', $associazione->slug) }}">
                @if (filled($associazione->logo_url))
                    <img src="{{ $associazione->logo_url }}" alt="Logo {{ $associazione->nome }}" class="pubblico-logo">
                @else
                    <i class="bi bi-people-fill text-primary fs-4"></i>
                @endif
                <span class="fw-semibold">{{ $associazione->nome }}</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navPubblico"
                    aria-controls="navPubblico" aria-expanded="false" aria-label="Apri il menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navPubblico">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('pubblico.eventi.*') ? 'active' : '' }}"
                           href="{{ route('pubblico.eventi.index', $associazione->slug) }}">Eventi</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2 py-2 py-md-0">
                    <button id="themeToggle" type="button" class="btn btn-sm btn-outline-secondary"
                            aria-label="Cambia tema chiaro/scuro" title="Tema chiaro/scuro">
                        <i class="bi bi-circle-half"></i>
                    </button>
                    @if (auth('account')->check())
                        @php /** @var \App\Models\Account $accountLayout */ $accountLayout = auth('account')->user(); @endphp
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle me-1"></i>{{ $accountLayout->nome_completo }}
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                @if (Route::has('pubblico.account.dashboard'))
                                    <li>
                                        <a class="dropdown-item" href="{{ route('pubblico.account.dashboard', $associazione->slug) }}">
                                            <i class="bi bi-speedometer2 me-2"></i>Area riservata
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                @endif
                                <li>
                                    <form method="POST" action="{{ route('pubblico.logout', $associazione->slug) }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item">
                                            <i class="bi bi-box-arrow-right me-2"></i>Esci
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    @else
                        <a href="{{ route('pubblico.login', $associazione->slug) }}" class="btn btn-sm btn-outline-secondary">Accedi</a>
                        <a href="{{ route('pubblico.registrazione', $associazione->slug) }}" class="btn btn-sm btn-primary">Registrati</a>
                    @endif
                </div>
            </div>
        </div>
    </nav>

    <main class="flex-grow-1 py-4">
        <div class="container">
            @include('partials.flash')
            @if (session('status'))
                <div class="alert alert-info mt-3" role="alert">{{ session('status') }}</div>
            @endif
            @yield('content')
        </div>
    </main>

    <footer class="pubblico-footer py-4 mt-4">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 small text-muted">
            <span>&copy; {{ now()->year }} {{ $associazione->nome }}</span>
            <a href="{{ route('privacy-policy.show', $associazione->id) }}" class="link-secondary">Informativa sulla privacy</a>
        </div>
    </footer>

    <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script>
        // Toggle tema chiaro/scuro (stessa chiave localStorage dell'app)
        document.getElementById('themeToggle').addEventListener('click', function () {
            var html = document.documentElement;
            var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
        });
    </script>
    @stack('scripts')
</body>
</html>
