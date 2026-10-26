@extends('area-soci.layouts.app')

@section('title', 'Assemblee - Area Riservata')

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-people me-2"></i>Assemblee</h1>
    </div>

    @if ($assemblee->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-people display-5 d-block mb-2"></i>
            Nessuna assemblea convocata al momento.
        </div>
    @endif

    @foreach ($assemblee as $voce)
        @php
            /** @var \App\Models\Riunione $riunione */
            $riunione = $voce['riunione'];
            $partecipante = $voce['partecipante'];
            $delegaData = $voce['delegaData'];
            $aperta = $riunione->stato === 'pianificata' && $riunione->data_ora->isFuture();
            $odg = is_array($riunione->odg) ? $riunione->odg : [];
        @endphp
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">{{ $riunione->titolo }}</span>
                <span class="d-flex align-items-center gap-2">
                    <span class="text-muted small">{{ $riunione->organoLabel() }}</span>
                    @if ($riunione->voto_online || $riunione->partecipazione_remota)
                        <a href="{{ route('area-soci.assemblee.show', $riunione) }}" class="btn btn-sm btn-primary">{{ $riunione->data_ora->isFuture() ? 'Dettagli' : 'Partecipa e vota' }}</a>
                    @endif
                </span>
            </div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-sm-4">Prima convocazione</dt><dd class="col-sm-8">{{ $riunione->data_ora->format('d/m/Y H:i') }}</dd>
                    @if ($riunione->seconda_convocazione_at)
                        <dt class="col-sm-4">Seconda convocazione</dt><dd class="col-sm-8">{{ $riunione->seconda_convocazione_at->format('d/m/Y H:i') }}</dd>
                    @endif
                    <dt class="col-sm-4">Luogo</dt><dd class="col-sm-8">{{ $riunione->luogo ?? $riunione->spazio?->nome ?? 'da definire' }}</dd>
                    <dt class="col-sm-4">Il tuo diritto di voto</dt>
                    <dd class="col-sm-8">{{ $partecipante->avente_diritto_voto ? 'Sì' : 'Partecipi senza diritto di voto' }}</dd>
                </dl>

                @if ($odg !== [])
                    <h6>Ordine del giorno</h6>
                    <ol class="mb-3">
                        @foreach ($odg as $punto)
                            <li>{{ is_array($punto) ? ($punto['punto'] ?? '') : $punto }}</li>
                        @endforeach
                    </ol>
                @endif

                @if ($voce['delegheRicevute']->isNotEmpty())
                    <div class="alert alert-info">
                        <i class="bi bi-person-check me-1"></i>
                        Hai ricevuto la delega di {{ $voce['delegheRicevute']->map(fn ($d) => $d->delegante?->nome_completo)->filter()->join(', ') }}.
                        Ricordati di partecipare: voterai anche a loro nome.
                    </div>
                @endif

                @if ($riunione->ammetteDeleghe() && $partecipante->avente_diritto_voto)
                    <h6 class="mt-3">Non puoi partecipare?</h6>
                    @if ($delegaData)
                        <p class="mb-2">Hai delegato <strong>{{ $delegaData->delegato?->nome_completo }}</strong>. Se partecipi di persona la delega decade e voti tu.</p>
                        @if ($aperta)
                            <form method="POST" action="{{ route('area-soci.assemblee.revoca', $riunione) }}"
                                  onsubmit="return confirm('Revocare la delega?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm">Revoca la delega</button>
                            </form>
                        @endif
                    @elseif ($aperta && $voce['delegheRicevute']->isEmpty())
                        <p class="text-muted small mb-2">
                            Puoi delegare un altro socio con diritto di voto. Indica il suo numero socio e il cognome.
                            Ogni socio può ricevere al massimo {{ $riunione->max_deleghe_per_socio }} {{ $riunione->max_deleghe_per_socio === 1 ? 'delega' : 'deleghe' }}.
                        </p>
                        <form method="POST" action="{{ route('area-soci.assemblee.delega', $riunione) }}" class="row g-2">
                            @csrf
                            <div class="col-sm-4">
                                <label class="form-label small" for="numero-{{ $riunione->id }}">Numero socio</label>
                                <input type="text" name="numero_socio" id="numero-{{ $riunione->id }}" class="form-control" maxlength="50" required>
                            </div>
                            <div class="col-sm-5">
                                <label class="form-label small" for="cognome-{{ $riunione->id }}">Cognome</label>
                                <input type="text" name="cognome" id="cognome-{{ $riunione->id }}" class="form-control" maxlength="100" required>
                            </div>
                            <div class="col-sm-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Delega</button>
                            </div>
                        </form>
                    @elseif (! $aperta)
                        <p class="text-muted small mb-0">Le deleghe online sono chiuse: per necessità rivolgiti alla segreteria.</p>
                    @endif
                @endif
            </div>
        </div>
    @endforeach
@endsection
