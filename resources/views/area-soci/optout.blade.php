@extends('layouts.guest')

@section('title', 'Disiscrizione comunicazioni email')
@section('brand-icon', 'bi-envelope-slash')
@section('heading', 'Comunicazioni email')
@section('subheading', $socio->associazione?->nome ?? 'Gestione Associazioni')

@section('content')
    @if ($confermato)
        <div class="text-center">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 2.5rem;"></i>
            <p class="mt-3 mb-1 fw-semibold">Disiscrizione confermata</p>
            <p class="text-muted small mb-0">
                L'indirizzo <strong>{{ $socio->email }}</strong> non riceverà più comunicazioni email.
                Puoi riattivarle in qualsiasi momento dal tuo profilo nell'area soci.
            </p>
        </div>
    @else
        <p class="mb-3">
            Vuoi annullare l'iscrizione alle comunicazioni email per
            <strong>{{ $socio->email }}</strong>?
        </p>
        <form method="POST" action="{{ route('area-soci.optout.store', ['token' => $token]) }}">
            @csrf
            <button type="submit" class="btn-login">Annulla iscrizione</button>
        </form>
    @endif
@endsection

@section('footer')
    <a href="{{ route('area-soci.login') }}" class="login-footer-link">Vai all'area soci</a>
@endsection
