<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php /** @var \App\Models\Socio $socioLayout */ $socioLayout = auth('socio')->user(); @endphp
    <title>@yield('title', 'Area Riservata - '.($socioLayout?->nome_completo ?? 'Socio'))</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#FF7B11">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    @stack('styles')
</head>
<body>
    <!-- Header mobile -->
    <div class="mobile-header d-md-none">
        <button class="sidebar-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-list"></i>
            <span>Menu</span>
        </button>
        <div class="flex-grow-1 text-center">
            <span class="fw-semibold">Area Soci</span>
        </div>
        <div style="width: 70px;"></div>
    </div>

    <div class="sidebar-overlay d-md-none" id="sidebarOverlay"></div>

    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse" id="mainSidebar">
                <div class="position-sticky pt-3">
                    <div class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none px-3 sidebar-brand">
                        <i class="bi bi-person-badge fs-4 me-2 text-primary"></i>
                        <span class="fs-5 fw-semibold">{{ $socioLayout?->associazione?->nome ?? 'Area Soci' }}</span>
                    </div>

                    <hr>

                    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
                        <span>Area Riservata</span>
                    </h6>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('area-soci.dashboard') ? 'active' : '' }}" href="{{ route('area-soci.dashboard') }}">
                                <i class="bi bi-speedometer2 me-2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('area-soci.profilo') ? 'active' : '' }}" href="{{ route('area-soci.profilo') }}">
                                <i class="bi bi-person me-2"></i>
                                Il Mio Profilo
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('area-soci.quote') ? 'active' : '' }}" href="{{ route('area-soci.quote') }}">
                                <i class="bi bi-cash-coin me-2"></i>
                                Le Mie Quote
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('area-soci.tessere*') ? 'active' : '' }}" href="{{ route('area-soci.tessere') }}">
                                <i class="bi bi-credit-card-2-front me-2"></i>
                                Le Mie Tessere
                            </a>
                        </li>
                    </ul>

                    <hr>

                    <div class="px-3 mt-auto">
                        <div class="d-flex align-items-center mb-2 text-muted">
                            <i class="bi bi-person-circle me-2"></i>
                            <span class="small">{{ $socioLayout?->nome_completo }}</span>
                        </div>
                        <form method="POST" action="{{ route('area-soci.logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                <i class="bi bi-box-arrow-right me-1"></i> Esci
                            </button>
                        </form>
                    </div>
                </div>
            </nav>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" id="mainContent">
                <div class="page-container" data-page="{{ \Illuminate\Support\Str::slug(request()->route()?->getName() ?? 'area-soci') }}">
                    @include('partials.flash')
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/main.js') }}"></script>
    <script>
        // PWA: registrazione service worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('service-worker.js') }}').catch(function () {});
            });
        }
    </script>
    @stack('scripts')
</body>
</html>
