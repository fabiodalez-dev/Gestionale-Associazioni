<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Gestione Associazioni'))</title>
    <script>
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
        body.guest-body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--ds-bg);
        }
        .login-wrapper { width: 100%; max-width: 400px; padding: 16px; }
        .login-brand { text-align: center; margin-bottom: 28px; }
        .login-brand-icon {
            width: 44px;
            height: 44px;
            background: var(--ds-accent);
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }
        .login-brand-icon i { color: #fff; font-size: 1.3125rem; }
        .login-brand h1 { font-size: 1.1875rem; font-weight: 600; margin: 0 0 3px; letter-spacing: -0.01em; }
        .login-brand p { font-size: 0.84375rem; color: var(--ds-text-muted); margin: 0; }
        .login-card {
            background: var(--ds-surface);
            border: 1px solid var(--ds-border);
            border-radius: var(--ds-radius-lg);
            padding: 28px;
            box-shadow: var(--ds-shadow-sm);
        }
        .btn-login {
            width: 100%;
            background: var(--ds-accent);
            border: 1px solid var(--ds-accent);
            color: #fff;
            font-weight: 500;
            font-size: 0.875rem;
            padding: 9px 16px;
            border-radius: var(--ds-radius);
            cursor: pointer;
            transition: background-color 150ms ease-out;
        }
        .btn-login:hover { background: var(--ds-accent-hover); border-color: var(--ds-accent-hover); }
        .btn-login:focus-visible { box-shadow: 0 0 0 3px var(--ds-accent-ring); outline: none; }
        .login-alert {
            border-radius: var(--ds-radius);
            padding: 11px 14px;
            font-size: 0.8125rem;
            margin-bottom: 14px;
            border: 1px solid;
        }
        .login-alert-error { background: var(--ds-danger-subtle); border-color: oklch(from var(--ds-danger) l c h / 0.3); color: var(--ds-danger); }
        .login-alert-info { background: var(--ds-info-subtle); border-color: oklch(from var(--ds-info) l c h / 0.3); color: var(--ds-info); }
        .login-footer-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-size: 0.8125rem;
            color: var(--ds-text-muted);
            text-decoration: none;
        }
        .login-footer-link:hover { color: var(--ds-accent); }
    </style>
    @stack('styles')
</head>
<body class="guest-body">
    <button type="button" class="theme-toggle-icon theme-toggle-floating" id="themeToggle" aria-label="Cambia tema chiaro/scuro" title="Tema chiaro/scuro">
        <i class="bi bi-moon theme-label-light"></i>
        <i class="bi bi-sun theme-label-dark"></i>
    </button>
    <div class="login-wrapper">
        <div class="login-brand">
            <div class="login-brand-icon">
                <i class="bi @yield('brand-icon', 'bi-people-fill')"></i>
            </div>
            <h1>@yield('heading', 'Gestione Associazioni')</h1>
            <p>@yield('subheading', 'Accedi al pannello di gestione')</p>
        </div>

        @if ($errors->any())
            <div class="login-alert login-alert-error" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="login-alert login-alert-info" role="alert">
                {{ session('status') }}
            </div>
        @endif

        <div class="login-card">
            @yield('content')
        </div>

        @yield('footer')
    </div>

    <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script>
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
