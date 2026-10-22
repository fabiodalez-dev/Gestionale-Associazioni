@extends('layouts.guest')

@section('title', 'Iscrizione — '.config('app.name', 'Gestione Associazioni'))
@section('brand-icon', $fase === 'conferma' ? 'bi-envelope-check' : ($fase === 'rifiutata' ? 'bi-x-circle' : 'bi-hourglass-split'))
@section('heading', $fase === 'conferma' ? 'Controlla la tua email' : ($fase === 'rifiutata' ? 'Richiesta non accolta' : 'Email confermata'))
@section('subheading', $nome ?? '')

@section('content')
    @if ($fase === 'conferma')
        <p class="mb-3">Abbiamo inviato un link di conferma{{ $email ? ' a '.$email : '' }}.</p>
        <p class="mb-0 text-muted">Apri l'email e clicca su <strong>Conferma l'email</strong> entro {{ \App\Services\Piattaforma\RegistrazioneAssociazioni::ORE_VALIDITA_LINK }} ore: senza conferma la richiesta non viene presa in carico.</p>
    @elseif ($fase === 'revisione')
        <p class="mb-3">Grazie, l'email è confermata e la richiesta di iscrizione{{ $nome ? ' di '.$nome : '' }} è arrivata.</p>
        <p class="mb-0 text-muted">La esaminiamo e ti scriviamo appena l'associazione è attiva: da quel momento potrai accedere con la password che hai scelto.</p>
    @else
        <p class="mb-0">Questa richiesta è già stata esaminata e non è stata accolta. Controlla l'email che ti abbiamo inviato per i dettagli.</p>
    @endif
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="login-footer-link">Torna all'accesso</a>
@endsection
