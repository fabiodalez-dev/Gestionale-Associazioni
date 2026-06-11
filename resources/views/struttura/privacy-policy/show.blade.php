<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — {{ $associazione->nome }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <style>
        body { background: #F3F4F6; }
        .brand-header { background: #FF7B11; color: #FFFFFF; padding: 1.5rem 0; }
        .brand-header img { max-height: 56px; background: #FFFFFF; border-radius: 8px; padding: 4px; }
        .policy-card { max-width: 860px; margin: -1.5rem auto 2rem; }
        footer { color: #9CA3AF; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="brand-header text-center mb-5">
        @if ($associazione->logo_url)
            <img src="{{ asset('storage/'.$associazione->logo_url) }}" alt="Logo {{ $associazione->nome }}" class="mb-2"><br>
        @endif
        <h1 class="h4 mb-0">{{ $associazione->nome }}</h1>
    </div>

    <div class="container policy-card">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Privacy Policy</h2>
                @if (blank($associazione->privacy_policy_html))
                    <div class="alert alert-info mb-0">
                        Privacy policy non ancora disponibile per questa associazione.
                    </div>
                @else
                    {{-- HTML sanitizzato in scrittura con purifier (spec §7, §9.10) --}}
                    {!! $associazione->privacy_policy_html !!}
                @endif
            </div>
        </div>
    </div>

    <footer class="text-center pb-4">{{ $associazione->nome }}</footer>
</body>
</html>
