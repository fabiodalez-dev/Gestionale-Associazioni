@extends('layouts.app')

@section('title', 'Annunci')

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-megaphone me-2"></i>Bacheca annunci</h1>
    @can('create', \App\Models\Annuncio::class)
        <a href="{{ route('annunci.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Nuovo annuncio
        </a>
    @endcan
</div>

<p class="text-muted small">
    Gli annunci pubblicati appaiono nella sezione <strong>Novità</strong> del mini-sito pubblico;
    quelli "solo soci" sono visibili nella bacheca dell'area riservata. Gli annunci pubblicati
    possono essere inclusi nelle <a href="{{ route('newsletter.index') }}">newsletter</a>.
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Titolo</th>
                    <th>Stato</th>
                    <th>Pubblicazione</th>
                    <th>Visibilità</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($annunci as $annuncio)
                    <tr>
                        <td>
                            <strong>{{ $annuncio->titolo }}</strong>
                            @if ($annuncio->in_evidenza)
                                <span class="badge bg-warning text-dark ms-1"><i class="bi bi-star-fill me-1"></i>In evidenza</span>
                            @endif
                        </td>
                        <td>
                            @if ($annuncio->isPubblicato())
                                <span class="badge bg-success">Pubblicato</span>
                            @elseif ($annuncio->pubblicato_dal === null)
                                <span class="badge bg-secondary">Bozza</span>
                            @elseif ($annuncio->pubblicato_dal->isFuture())
                                <span class="badge bg-info text-dark">Programmato</span>
                            @else
                                <span class="badge bg-dark">Scaduto</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($annuncio->pubblicato_dal)
                                dal {{ $annuncio->pubblicato_dal->format('d/m/Y H:i') }}
                                @if ($annuncio->pubblicato_al)
                                    al {{ $annuncio->pubblicato_al->format('d/m/Y H:i') }}
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($annuncio->solo_soci)
                                <span class="badge bg-primary"><i class="bi bi-person-badge me-1"></i>Solo soci</span>
                            @else
                                <span class="badge bg-light text-dark border">Pubblico</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @can('update', $annuncio)
                                <a href="{{ route('annunci.edit', $annuncio) }}" class="btn btn-sm btn-outline-warning" title="Modifica">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            @endcan
                            @can('delete', $annuncio)
                                <form method="POST" action="{{ route('annunci.destroy', $annuncio) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare definitivamente questo annuncio?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-megaphone me-1"></i>Nessun annuncio in bacheca. Crea il primo!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-center mt-3">
    {{ $annunci->links() }}
</div>
@endsection
