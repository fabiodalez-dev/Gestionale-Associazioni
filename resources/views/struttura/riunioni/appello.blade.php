@extends('layouts.app')

@section('title', 'Appello — '.$riunione->titolo)

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <a href="{{ route('riunioni.show', $riunione) }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>{{ $riunione->titolo }}</a>
        <h1 class="h2 mb-0">Appello</h1>
        <div class="text-muted">{{ $riunione->organoLabel() }} del {{ $riunione->data_ora?->format('d/m/Y') }}</div>
    </div>
</div>

@include('partials.flash')

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Lettura tessera: i lettori QR/barcode scrivono il codice e premono invio --}}
        @can('update', $riunione)
        <form method="POST" action="{{ route('riunioni.appello.codice', $riunione) }}" class="card mb-3">
            @csrf
            <div class="card-body">
                <label for="codiceAppello" class="form-label fw-semibold">Registra presenza</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-qr-code-scan"></i></span>
                    <input type="text" name="codice" id="codiceAppello" class="form-control" autocomplete="off" autofocus
                           placeholder="Leggi il QR della tessera o scrivi il numero tessera / numero socio" maxlength="255" required>
                    <button type="submit" class="btn btn-primary">Registra</button>
                </div>
            </div>
        </form>
        @endcan

        <form method="GET" action="{{ route('riunioni.appello', $riunione) }}" class="row g-2 mb-3">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Cognome, nome o numero socio" value="{{ $search }}">
                </div>
            </div>
            <div class="col-md-3">
                <select name="filtro" class="form-select" aria-label="Filtro presenze">
                    <option value="tutti" @selected($filtro === 'tutti')>Tutti</option>
                    <option value="presenti" @selected($filtro === 'presenti')>Presenti</option>
                    <option value="non_presenti" @selected($filtro === 'non_presenti')>Non presenti</option>
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-outline-primary">Filtra</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Socio</th>
                        <th>N. socio</th>
                        <th>Voto</th>
                        <th>Presenza</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($partecipanti as $p)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $p->socio?->cognome }} {{ $p->socio?->nome }}</div>
                                @if ($delegheRicevute->has($p->socio_id))
                                    <div class="small text-muted">Delegato da {{ $delegheRicevute[$p->socio_id]->map(fn ($d) => $d->delegante?->nome_completo)->join(', ') }}</div>
                                @endif
                                @if ($delegheDate->has($p->socio_id))
                                    <div class="small text-muted">Ha delegato {{ $delegheDate[$p->socio_id]->delegato?->nome_completo }}{{ $p->isPresente() ? ' (delega decaduta: presente di persona)' : '' }}</div>
                                @endif
                            </td>
                            <td class="tabular text-muted">{{ $p->socio?->numero_socio ?? '—' }}</td>
                            <td>
                                @if ($p->avente_diritto_voto)
                                    <span class="small">Sì</span>
                                @else
                                    <span class="small text-muted">Senza voto</span>
                                @endif
                            </td>
                            <td>
                                @if ($p->isPresente())
                                    <span class="text-success small"><i class="bi bi-check-circle me-1"></i>Presente{{ $p->presente_at ? ' dalle '.$p->presente_at->format('H:i') : '' }}</span>
                                @else
                                    <span class="text-muted small">Non presente</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('update', $riunione)
                                <form method="POST" action="{{ route('riunioni.partecipanti.presenza', [$riunione, $p]) }}" class="d-inline">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="ritorno" value="appello">
                                    <input type="hidden" name="search" value="{{ $search }}">
                                    <input type="hidden" name="filtro" value="{{ $filtro }}">
                                    <input type="hidden" name="page" value="{{ $partecipanti->currentPage() }}">
                                    @if ($p->isPresente())
                                        <input type="hidden" name="stato" value="invitato">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Annulla presenza</button>
                                    @else
                                        <input type="hidden" name="stato" value="confermato">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Presente</button>
                                    @endif
                                </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Nessun socio trovato.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $partecipanti->links() }}
    </div>

    <div class="col-lg-4">
        @include('struttura.riunioni._quorum', ['riunione' => $riunione, 'quorum' => $quorum])
    </div>
</div>
@endsection
