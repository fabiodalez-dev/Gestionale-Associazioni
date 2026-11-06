@extends('layouts.app')

@section('title', $pagina->titolo)

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <div>
        <a href="{{ route('manuale.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Manuale · {{ $pagina->sezione }}</a>
        <h1 class="h2 mb-0">{{ $pagina->titolo }}</h1>
        <div class="small text-muted">
            Rivista il {{ $pagina->revisionata_at?->format('d/m/Y') ?? '—' }}
            @if ($pagina->responsabile) · responsabile {{ $pagina->responsabile->nome_completo }}@endif
            · {{ \App\Models\ManualePagina::VISIBILITA[$pagina->visibilita] ?? $pagina->visibilita }}
        </div>
    </div>
    <div class="d-flex gap-2">
        @can('update', $pagina)
            <a href="{{ route('manuale.edit', $pagina) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Modifica</a>
        @endcan
        @can('delete', $pagina)
            <form method="POST" action="{{ route('manuale.destroy', $pagina) }}" onsubmit="return confirm('Eliminare la pagina?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3"></i></button>
            </form>
        @endcan
    </div>
</div>

@include('partials.flash')

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-body manuale-contenuto">
                @if ($pagina->contenutoHtml() !== '')
                    {!! $pagina->contenutoHtml() !!}
                @else
                    <p class="text-muted mb-0">Nessun testo.</p>
                @endif
            </div>
        </div>

        @if ($pagina->elencoPassi() !== [])
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-list-check me-2"></i>Passi</span>
                    <form method="POST" action="{{ route('manuale.avvia', $pagina) }}" class="d-flex gap-2">
                        @csrf
                        <input type="text" name="titolo" class="form-control form-control-sm" maxlength="200" placeholder="{{ $pagina->titolo }} — {{ now()->format('d/m/Y') }}" aria-label="Titolo della checklist">
                        <button type="submit" class="btn btn-sm btn-primary text-nowrap">Avvia checklist</button>
                    </form>
                </div>
                <ol class="list-group list-group-flush list-group-numbered">
                    @foreach ($pagina->elencoPassi() as $passo)
                        <li class="list-group-item">{{ $passo }}</li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        @if ($adempimenti->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header small">Adempimenti collegati</div>
                <ul class="list-group list-group-flush small">
                    @foreach ($adempimenti as $adempimento)
                        <li class="list-group-item">{{ $adempimento->titolo }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header small">Checklist</div>
            <ul class="list-group list-group-flush small">
                @forelse ($esecuzioni as $esecuzione)
                    <li class="list-group-item d-flex justify-content-between">
                        <a href="{{ route('manuale.esecuzioni.show', $esecuzione) }}" class="text-decoration-none">{{ $esecuzione->titolo }}</a>
                        <span class="tabular {{ $esecuzione->completata_at ? 'text-success' : 'text-muted' }}">{{ $esecuzione->passi_fatti_count }}/{{ $esecuzione->passi_count }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nessuna checklist avviata.</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <div class="card-header small">Storico delle revisioni</div>
            <ul class="list-group list-group-flush small">
                @forelse ($revisioni as $revisione)
                    @php
                        $cambi = $revisione->attribute_changes ?? $revisione->properties ?? collect();
                        $vecchio = $cambi['old']['contenuto'] ?? null;
                    @endphp
                    <li class="list-group-item">
                        <div>{{ $revisione->created_at?->format('d/m/Y H:i') }} · {{ $revisione->causer?->nome_completo ?? 'Sistema' }}
                            <span class="text-muted">· {{ $revisione->event === 'created' ? 'creata' : ($revisione->event === 'updated' ? 'modificata' : $revisione->event) }}</span>
                        </div>
                        @if (filled($vecchio))
                            <details class="mt-1">
                                <summary class="text-muted">Testo precedente</summary>
                                <pre class="small mb-0 mt-1" style="white-space: pre-wrap">{{ $vecchio }}</pre>
                            </details>
                        @endif
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nessuna revisione registrata.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
