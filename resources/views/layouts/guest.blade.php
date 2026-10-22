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
    <meta name="theme-color" content="#0D9488">
    <style>
        body.guest-body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--ds-bg);
            background-image:
                radial-gradient(700px 420px at 15% -10%, rgba(13, 148, 136, 0.12), transparent 70%),
                radial-gradient(600px 380px at 100% 110%, rgba(232, 162, 58, 0.10), transparent 70%);
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
        .login-wrapper { width: 100%; max-width: 420px; padding: 24px 16px; }
        .login-brand { text-align: center; margin-bottom: 26px; }
        .login-brand-icon {
            width: 52px;
            height: 52px;
            background: var(--ds-accent);
            border-radius: 14px;
            display: inline-grid;
            place-items: center;
            margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(13, 148, 136, 0.35);
        }
        .login-brand-icon i { color: #fff; font-size: 1.5rem; }
        .login-brand h1 { font-size: 1.375rem; font-weight: 700; margin: 0 0 4px; letter-spacing: -0.02em; }
        .login-brand p { font-size: 0.875rem; color: var(--ds-text-muted); margin: 0; }
        .login-card {
            background: var(--ds-surface);
            border-radius: 16px;
            padding: 30px 30px 26px;
            box-shadow: var(--ds-shadow-lg);
        }
        .login-card .form-control { min-height: 44px; }
        .btn-login {
            width: 100%;
            min-height: 44px;
            background: var(--ds-accent);
            border: 1px solid var(--ds-accent);
            color: #fff;
            font-weight: 600;
            font-size: 0.9375rem;
            padding: 9px 16px;
            border-radius: var(--ds-radius);
            cursor: pointer;
            box-shadow: var(--ds-shadow-accent);
            transition: background-color 150ms ease-out, box-shadow 150ms ease-out;
        }
        .btn-login:hover { background: var(--ds-accent-hover); border-color: var(--ds-accent-hover); }
        .btn-login:focus-visible { box-shadow: 0 0 0 3px var(--ds-accent-ring); outline: none; }
        .login-alert {
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.84375rem;
            margin-bottom: 14px;
        }
        .login-alert-error { background: var(--ds-danger-subtle); color: var(--ds-danger-ink); }
        .login-alert-info { background: var(--ds-info-subtle); color: var(--ds-info-ink); }
        .login-footer-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 0.84375rem;
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
