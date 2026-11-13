@extends('layouts.guest')

@section('title', 'Area Soci - Scegli l\'associazione')
@section('brand-icon', 'bi-person-badge')
@section('heading', 'Area Soci')
@section('subheading', 'Scegli l\'associazione')

@section('content')
    <p class="text-muted small mb-3">
        Il tuo indirizzo email è registrato in più associazioni. Scegli in quale area soci vuoi entrare:
    </p>

    @foreach ($soci as $socio)
        <form method="POST" action="{{ route('area-soci.login.attempt') }}" class="mb-2">
            @csrf
            <input type="hidden" name="socio_scelto" value="{{ $socio->id }}">
            <button type="submit" class="btn-login">
                <i class="bi bi-building me-1"></i>{{ $socio->associazione?->nome ?? 'Associazione' }}
            </button>
        </form>
    @endforeach

    <form method="POST" action="{{ route('area-soci.login.attempt') }}" class="mt-3 text-center">
        @csrf
        <input type="hidden" name="annulla_scelta" value="1">
        <button type="submit" class="btn btn-link btn-sm">Annulla e torna al login</button>
    </form>
@endsection
