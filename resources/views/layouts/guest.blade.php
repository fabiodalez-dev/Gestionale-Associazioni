<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Gestione Associazioni'))</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        @font-face {
            font-family: 'Inter';
            src: url('{{ asset('assets/fonts/InterVariable.woff2') }}') format('woff2');
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #F3F4F6;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
        }
        .login-wrapper {
            width: 100%;
            max-width: 400px;
            padding: 1rem;
        }
        .login-brand {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-brand-icon {
            width: 48px;
            height: 48px;
            background: #FF7B11;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        .login-brand-icon i {
            color: #FFFFFF;
            font-size: 1.5rem;
        }
        .login-brand h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 0.25rem;
        }
        .login-brand p {
            font-size: 0.875rem;
            color: #9CA3AF;
            margin: 0;
        }
        .login-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .form-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: #4B5563;
            margin-bottom: 0.375rem;
        }
        .form-control {
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: #111827;
            transition: border-color 150ms, box-shadow 150ms;
        }
        .form-control:focus {
            border-color: #FF7B11;
            box-shadow: 0 0 0 3px rgba(255,123,17,0.15);
            outline: none;
        }
        .form-control::placeholder { color: #9CA3AF; }
        .btn-login {
            width: 100%;
            background: #FF7B11;
            border: none;
            color: #FFFFFF;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.625rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background 150ms;
        }
        .btn-login:hover { background: #E86A00; }
        .btn-login:active { transform: translateY(0); }
        .alert-login-error {
            border: none;
            border-left: 3px solid #DC2626;
            background: #FEE2E2;
            color: #7F1D1D;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.8125rem;
            margin-bottom: 1rem;
        }
        .alert-login-info {
            border: none;
            border-left: 3px solid #2563EB;
            background: #DBEAFE;
            color: #1E3A8A;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.8125rem;
            margin-bottom: 1rem;
        }
        .login-footer-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            font-size: 0.8125rem;
            color: #6B7280;
            text-decoration: none;
        }
        .login-footer-link:hover { color: #FF7B11; }
    </style>
    @stack('styles')
</head>
<body>
    <div class="login-wrapper">
        <div class="login-brand">
            <div class="login-brand-icon">
                <i class="bi @yield('brand-icon', 'bi-people-fill')"></i>
            </div>
            <h1>@yield('heading', 'Gestione Associazioni')</h1>
            <p>@yield('subheading', 'Accedi al pannello di gestione')</p>
        </div>

        @if ($errors->any())
            <div class="alert-login-error" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="alert-login-info" role="alert">
                {{ session('status') }}
            </div>
        @endif

        <div class="login-card">
            @yield('content')
        </div>

        @yield('footer')
    </div>

    <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    @stack('scripts')
</body>
</html>
