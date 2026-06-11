<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Installazione') - Gestione Associazioni</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        body.installer-body {
            min-height: 100vh;
            padding: 48px 16px;
            background-color: var(--ds-bg);
            background-image: radial-gradient(900px 480px at 50% -120px, oklch(72% 0.185 55 / 0.09), transparent 70%);
            background-repeat: no-repeat;
        }
        .installer-wrapper { max-width: 640px; margin-inline: auto; }
        .installer-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
        .installer-brand-icon {
            width: 40px; height: 40px; border-radius: 11px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(180deg, oklch(75% 0.185 58), oklch(67% 0.19 50));
            color: #fff; font-size: 1.125rem;
            box-shadow: var(--ds-glow-accent), inset 0 1px 0 oklch(100% 0 0 / 0.25);
        }
        .installer-brand h1 { font-size: 1.1875rem; margin: 0; }
        .installer-brand p { font-size: 0.8125rem; color: var(--ds-text-muted); margin: 0; }
        .installer-steps { display: flex; gap: 6px; margin-bottom: 20px; }
        .installer-step {
            flex: 1; height: 4px; border-radius: 999px;
            background: var(--ds-border);
        }
        .installer-step.done { background: var(--ds-accent); }
        .installer-card {
            background: var(--ds-surface);
            border: 1px solid var(--ds-border);
            border-radius: var(--ds-radius-lg);
            padding: 28px;
            box-shadow: var(--ds-shadow-lg);
        }
        .installer-card h2 { font-size: 1.0625rem; margin-bottom: 4px; }
        .installer-card .subtitle { font-size: 0.84375rem; color: var(--ds-text-muted); margin-bottom: 20px; }
        .check-list { list-style: none; margin: 0; padding: 0; }
        .check-list li {
            display: flex; align-items: center; justify-content: space-between;
            padding: 9px 0; font-size: 0.84375rem;
        }
        .check-list li + li { border-top: 1px solid var(--ds-border); }
        .check-ok { color: var(--ds-success); }
        .check-ko { color: var(--ds-danger); font-weight: 600; }
    </style>
</head>
<body class="installer-body">
    <div class="installer-wrapper">
        <div class="installer-brand">
            <span class="installer-brand-icon"><i class="bi bi-people-fill"></i></span>
            <div>
                <h1>Gestione Associazioni</h1>
                <p>Installazione guidata &middot; passo @yield('step-label')</p>
            </div>
        </div>

        <div class="installer-steps" aria-hidden="true">
            @for ($i = 1; $i <= 5; $i++)
                <span class="installer-step {{ $step >= $i ? 'done' : '' }}"></span>
            @endfor
        </div>

        @if (session('success'))
            <div class="alert alert-success mb-3">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger mb-3">{{ $errors->first() }}</div>
        @endif

        <div class="installer-card">
            @yield('content')
        </div>
    </div>
</body>
</html>
