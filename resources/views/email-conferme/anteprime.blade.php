@extends('layouts.app')

@section('title', 'Anteprime email — '.session('associazione_nome'))

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-eye me-2"></i>Anteprime email</h1>
    @if ($puoInviareProva && $anteprima !== null)
        <form method="POST" action="{{ route('email-anteprime.prova', ['codice' => $codice]) }}">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send me-1"></i>Invia prova a me
            </button>
        </form>
    @endif
</div>

@include('partials.flash')

<p class="text-muted">
    Tutte le notifiche di sistema renderizzate su <strong>dati di esempio</strong> con il layout
    brandizzato della tua associazione (logo, nome, colore). Nessun dato reale viene usato o salvato.
</p>

<div class="row g-3">
    {{-- Elenco notifiche raggruppate per categoria --}}
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="list-group list-group-flush">
                @php $categoriaCorrente = null; @endphp
                @foreach ($catalogo as $cod => $meta)
                    @if ($meta['categoria'] !== $categoriaCorrente)
                        @php $categoriaCorrente = $meta['categoria']; @endphp
                        <div class="list-group-item bg-light fw-bold small text-uppercase">{{ $categoriaCorrente }}</div>
                    @endif
                    <a href="{{ route('email-anteprime.index', ['codice' => $cod]) }}"
                       class="list-group-item list-group-item-action {{ $cod === $codice ? 'active' : '' }}">
                        <div>{{ $meta['nome'] }}</div>
                        <div class="small {{ $cod === $codice ? '' : 'text-muted' }}">{{ $meta['descrizione'] }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Anteprima --}}
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-envelope-paper me-1"></i>
                    @if ($anteprima !== null)
                        <strong>Oggetto:</strong> {{ $anteprima['subject'] }}
                    @else
                        Anteprima
                    @endif
                </span>
            </div>
            <div class="card-body p-0" style="min-height: 600px;">
                @if ($errore !== null)
                    <div class="alert alert-warning m-3">{{ $errore }}</div>
                @else
                    <iframe src="{{ route('email-anteprime.show', ['codice' => $codice]) }}"
                            title="Anteprima email"
                            sandbox=""
                            style="width:100%; height:640px; border:0;"></iframe>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
