<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — {{ $associazione->nome }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    @include('partials.stile-associazione')
    <style>
        body { background: #F3F4F6; }
        .brand-header { background: var(--brand); color: var(--brand-testo); padding: 1.5rem 0; }
        .brand-header img { max-height: 56px; background: #FFFFFF; border-radius: 8px; padding: 4px; }
        .policy-card { max-width: 860px; margin: -1.5rem auto 2rem; }
        footer { color: #9CA3AF; font-size: 0.8rem; }
        .privacy-testo h3 { font-size: 1.25rem; margin-bottom: 1rem; }
        .privacy-testo h4 { font-size: 1rem; margin-top: 1.25rem; }
    </style>
</head>
<body>
    <div class="brand-header text-center mb-5">
        @if ($associazione->moduli()->mostra_logo && $associazione->logoUrl())
            <img src="{{ $associazione->logoUrl('miniatura') }}" alt="Logo {{ $associazione->nome }}" class="mb-2"><br>
        @endif
        <h1 class="h4 mb-0">{{ $associazione->nome }}</h1>
    </div>

    <div class="container policy-card">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                @if (blank($associazione->privacy_policy_html))
                    {{-- Modello predefinito compilato con i dati dell'associazione --}}
                    <div class="privacy-testo">@include('privacy.informativa-predefinita', ['associazione' => $associazione])</div>
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
