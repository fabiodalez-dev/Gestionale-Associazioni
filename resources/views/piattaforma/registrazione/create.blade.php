@extends('layouts.guest')

@section('title', 'Iscrivi la tua associazione — '.config('app.name', 'Gestione Associazioni'))
@section('brand-icon', 'bi-buildings')
@section('heading', 'Iscrivi la tua associazione')
@section('subheading', $automatica ? 'Compila il modulo: l\'accesso è attivo subito.' : 'Compila il modulo: ti scriviamo appena la richiesta è approvata.')

@push('styles')
    <style>
        .login-wrapper { max-width: 760px; }
        .reg-step { display: flex; align-items: center; gap: 10px; margin: 4px 0 16px; }
        .reg-step .num { width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; background: var(--ds-accent-subtle); color: var(--ds-accent-ink); font-weight: 700; font-size: 0.8125rem; }
        .reg-step h2 { font-size: 1rem; margin: 0; }
        .reg-step small { display: block; color: var(--ds-text-muted); font-size: 0.78125rem; }
        .reg-sep { border-top: 1px solid var(--ds-line); margin: 8px 0 22px; }
    </style>
@endpush

@section('content')
    <form method="POST" action="{{ route('registrazione.store') }}" id="formRegistrazione" novalidate>
        @csrf
        <x-honeypot />

        <div class="reg-step">
            <span class="num">1</span>
            <div><h2>L'associazione</h2><small>I dati che compariranno su tessere, ricevute e pagine pubbliche</small></div>
        </div>
        @include('formello.campi', ['form' => $form, 'solo' => \App\Forms\RegistrazioneAssociazioneForm::GRUPPO_ASSOCIAZIONE])

        <div class="reg-sep"></div>
        <div class="reg-step">
            <span class="num">2</span>
            <div><h2>Il tuo account</h2><small>Sarai l'amministratore: potrai poi invitare altri collaboratori</small></div>
        </div>
        @include('formello.campi', ['form' => $form, 'solo' => \App\Forms\RegistrazioneAssociazioneForm::GRUPPO_ADMIN])

        @include('formello.campi', ['form' => $form, 'solo' => ['privacy']])

        <button type="submit" class="btn-login" id="btnRegistrati">
            {{ $automatica ? 'Crea l\'associazione' : 'Invia la richiesta' }}
        </button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="login-footer-link">Hai già un account? Accedi</a>
@endsection

@push('scripts')
    <script>
        document.getElementById('formRegistrazione').addEventListener('submit', function () {
            setTimeout(function () { document.getElementById('btnRegistrati').disabled = true; }, 0);
        });
    </script>
@endpush
