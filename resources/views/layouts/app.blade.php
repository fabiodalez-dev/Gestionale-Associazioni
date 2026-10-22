<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', session('app_name', config('app.name', 'Associazione Soci Manager')))</title>
    <script>
        // Applica il tema salvato prima del paint per evitare flash
        (function () {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#0D9488">
    @include('partials.pwa')
    @stack('styles')
</head>
<body>
    @auth
    <!-- Overlay sidebar per mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    @endauth

    <div class="app-shell">
        @auth
            @include('layouts.sidebar')
        @endauth

        <div class="app-main">
            @auth
                @include('layouts.topbar')
            @endauth

            <!-- Contenuto principale -->
            <main class="main-content" id="mainContent">
                <div class="page-container" data-page="{{ \Illuminate\Support\Str::slug(request()->route()?->getName() ?? 'dashboard') }}">
                    @include('partials.flash')
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/main.js') }}"></script>
    @auth
        {{-- Ricerca globale ⌘K (f.1 piano "Gestione 2" — modulo operativita) --}}
        @includeIf('operativita._global-search')
    @endauth
    @stack('scripts')
</body>
</html>
