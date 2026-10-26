@extends('area-soci.layouts.app')

@section('title', 'Segnala un messaggio - Area Riservata')

@section('content')
    <div class="pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Segnala un messaggio</h1>
    </div>
    <div class="card card-body" style="max-width: 36rem">
        <p>Messaggio di <strong>{{ $contatto->mittente?->nome_completo }}</strong> del {{ $contatto->created_at?->format('d/m/Y') }}: "{{ $contatto->oggetto }}".</p>
        @if ($contatto->segnalato_at)
            <p class="text-muted mb-0">Lo hai già segnalato il {{ $contatto->segnalato_at->format('d/m/Y') }}.</p>
        @else
            <p class="small text-muted">La segreteria saprà che hai ricevuto un messaggio inappropriato da questo socio tramite la rubrica.</p>
            <form method="POST" action="{{ route('area-soci.rubrica.segnala.store', $contatto) }}">
                @csrf
                <button type="submit" class="btn btn-danger">Segnala alla segreteria</button>
            </form>
        @endif
    </div>
@endsection
