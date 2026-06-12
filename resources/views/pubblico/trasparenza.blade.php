@extends('pubblico.layouts.app')

@section('title', 'Trasparenza — '.$associazione->nome)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-journal-check me-2"></i>Trasparenza</h1>
        <a href="{{ route('pubblico.home.show', $associazione->slug) }}" class="small">Torna alla home</a>
    </div>

    @if ($gruppi->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-journal-check display-5 d-block mb-2"></i>
            Nessun documento pubblicato al momento.
        </div>
    @else
        @foreach ($gruppi as $categoria => $documenti)
            <section class="mb-4">
                <h2 class="h5 mb-3 text-capitalize">
                    <i class="bi bi-folder2-open me-2"></i>{{ $categoria === 'bilancio' ? 'Bilanci' : ucfirst($categoria) }}
                </h2>
                <div class="card">
                    <ul class="list-group list-group-flush">
                        @foreach ($documenti as $documento)
                            <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <strong>{{ $documento->nome_file }}</strong>
                                    @if ($documento->descrizione)
                                        <div class="small text-muted">{{ $documento->descrizione }}</div>
                                    @endif
                                    <div class="small text-muted">
                                        @if ($documento->dimensione_kb)
                                            {{ number_format($documento->dimensione_kb, 0, ',', '.') }} KB ·
                                        @endif
                                        {{ $documento->created_at?->format('d/m/Y') }}
                                    </div>
                                </div>
                                <a href="{{ route('pubblico.trasparenza.download', [$associazione->slug, $documento->id]) }}"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-download me-1"></i>Scarica
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endforeach
    @endif
@endsection
