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
    <meta name="theme-color" content="#FF7B11">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    @stack('styles')
</head>
<body>
    @auth
    <!-- Header mobile (visibile solo su mobile per utenti autenticati) -->
    <div class="mobile-header d-md-none">
        <button class="sidebar-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-list"></i>
            <span>Menu</span>
        </button>
        <div class="flex-grow-1 text-center">
            <span class="fw-semibold">{{ session('associazione_nome', 'Manager') }}</span>
        </div>
        <div style="width: 70px;"></div> <!-- Spacer per centratura -->
    </div>

    <!-- Overlay sidebar per mobile -->
    <div class="sidebar-overlay d-md-none" id="sidebarOverlay"></div>
    @endauth

    <div class="app-shell">
        @auth
            @include('layouts.sidebar')
        @endauth

        <!-- Contenuto principale -->
        <main class="main-content" id="mainContent">
            <div class="page-container" data-page="{{ \Illuminate\Support\Str::slug(request()->route()?->getName() ?? 'dashboard') }}">
                @include('partials.flash')
                @yield('content')
            </div>
        </main>
    </div>

    <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/main.js') }}"></script>
    @stack('scripts')
</body>
</html>
