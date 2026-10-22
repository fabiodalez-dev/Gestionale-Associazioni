@extends('layouts.guest')

@section('title', 'Richiesta inviata — '.config('app.name', 'Gestione Associazioni'))
@section('brand-icon', 'bi-send-check')
@section('heading', 'Richiesta inviata')
@section('subheading', 'Grazie per averci scelto')

@section('content')
    <p class="mb-3">
        Abbiamo ricevuto la richiesta di iscrizione{{ $nome ? ' di '.$nome : '' }}.
    </p>
    <p class="mb-0 text-muted">
        La esaminiamo e ti scriviamo all'email che hai indicato appena l'associazione è attiva: da quel momento potrai accedere con la password che hai scelto.
    </p>
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="login-footer-link">Torna all'accesso</a>
@endsection
