@extends('layouts.app')

@section('title', 'Elezione — '.$elezione->organoLabel())

@php
    $statoColore = ['candidature' => 'secondary', 'voto' => 'primary', 'scrutinio' => 'warning', 'proclamata' => 'success'];
    $valide = $elezione->candidature->filter->isValida();
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <div>
        <a href="{{ route('riunioni.show', $riunione) }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>{{ $riunione->titolo }}</a>
        <h1 class="h2 mb-0">Elezione: {{ $elezione->organoLabel() }}
            <span class="badge bg-{{ $statoColore[$elezione->stato] ?? 'secondary' }} align-middle">{{ $elezione->statoLabel() }}</span>
        </h1>
    </div>
    @if ($elezione->stato === 'candidature')
        <form method="POST" action="{{ route('elezioni.destroy', $elezione) }}" onsubmit="return confirm('Eliminare l\'elezione e le candidature?');">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Elimina</button>
        </form>
    @endif
</div>

@include('partials.flash')

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-badge me-2"></i>Candidature</span>
                <span class="small text-muted">{{ $valide->count() }} valide su {{ $elezione->posti }} posti</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            @if (in_array($elezione->stato, ['scrutinio', 'proclamata'], true))<th>#</th>@endif
                            <th>Candidato/a</th>
                            <th>Stato</th>
                            @if (in_array($elezione->stato, ['scrutinio', 'proclamata'], true))<th class="text-end">Preferenze</th>@endif
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($elezione->candidature as $c)
                            <tr>
                                @if (in_array($elezione->stato, ['scrutinio', 'proclamata'], true))<td class="tabular">{{ $c->posizione ?? '—' }}</td>@endif
                                <td>
                                    <div class="fw-semibold">{{ $c->socio?->nome_completo }}</div>
                                    @if ($c->presentazione)<div class="small text-muted">{{ \Illuminate\Support\Str::limit($c->presentazione, 160) }}</div>@endif
                                </td>
                                <td class="small">
                                    @if ($c->ritirata_at)
                                        <span class="text-muted">Ritirata</span>
                                    @elseif (! $c->accettata_at)
                                        <span class="text-warning">In attesa di accettazione</span>
                                    @elseif ($c->eletta)
                                        <span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Eletto/a</span>
                                    @else
                                        <span>Valida{{ $c->origine === 'area_soci' ? ' (dall\'area soci)' : '' }}</span>
                                    @endif
                                </td>
                                @if (in_array($elezione->stato, ['scrutinio', 'proclamata'], true))<td class="text-end tabular">{{ $c->preferenze_totali ?? '—' }}</td>@endif
                                <td class="text-end">
                                    @if ($elezione->stato === 'candidature' && ! $c->ritirata_at)
                                        <div class="d-inline-flex gap-1">
                                            @unless ($c->accettata_at)
                                                <form method="POST" action="{{ route('elezioni.candidature.accetta', [$elezione, $c]) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Il candidato ha accettato (modulo firmato)">Accettata</button>
                                                </form>
                                            @endunless
                                            <form method="POST" action="{{ route('elezioni.candidature.ritira', [$elezione, $c]) }}" onsubmit="return confirm('Ritirare la candidatura?');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Ritira</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">Nessuna candidatura.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($elezione->stato === 'candidature')
                <div class="card-body border-top">
                    <form method="POST" action="{{ route('elezioni.candidature.store', $elezione) }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label small" for="candCodice">Socio</label>
                            <input type="text" name="codice" id="candCodice" class="form-control form-control-sm" placeholder="N. socio o tessera" maxlength="255" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small" for="candPres">Presentazione (facoltativa)</label>
                            <input type="text" name="presentazione" id="candPres" class="form-control form-control-sm" maxlength="2000">
                        </div>
                        <div class="col-md-3">
                            <input type="hidden" name="accettata" value="0">
                            <div class="form-check small mb-1">
                                <input class="form-check-input" type="checkbox" name="accettata" value="1" id="candAcc">
                                <label class="form-check-label" for="candAcc">Già accettata</label>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary w-100">Aggiungi</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        @if ($elezione->stato === 'voto')
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-ui-checks me-2"></i>Chiudi il voto e scrutina</div>
                <div class="card-body">
                    <p class="small text-muted">Schede online ricevute: <strong class="tabular">{{ $schedeOnline }}</strong>. Inserisci i risultati delle schede cartacee scrutinate in sala (lascia vuoto se non ce ne sono).</p>
                    <form method="POST" action="{{ route('elezioni.scrutinio', $elezione) }}" onsubmit="return confirm('Chiudere il voto e fare lo scrutinio?');">
                        @csrf
                        <div class="row g-2 mb-3">
                            <div class="col-4"><label class="form-label small" for="schValide">Schede valide in sala</label><input type="number" min="0" name="schede_valide" id="schValide" class="form-control form-control-sm"></div>
                            <div class="col-4"><label class="form-label small" for="schBianche">Bianche</label><input type="number" min="0" name="schede_bianche" id="schBianche" class="form-control form-control-sm"></div>
                            <div class="col-4"><label class="form-label small" for="schNulle">Nulle</label><input type="number" min="0" name="schede_nulle" id="schNulle" class="form-control form-control-sm"></div>
                        </div>
                        @foreach ($valide as $c)
                            <div class="row g-2 align-items-center mb-1">
                                <div class="col-8 small"><label for="pref-{{ $c->id }}">{{ $c->socio?->nome_completo }}</label></div>
                                <div class="col-4"><input type="number" min="0" name="preferenze[{{ $c->id }}]" id="pref-{{ $c->id }}" class="form-control form-control-sm" placeholder="Preferenze in sala"></div>
                            </div>
                        @endforeach
                        <button type="submit" class="btn btn-primary mt-2">Chiudi e scrutina</button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-body">
                <dl class="mb-0 small">
                    <dt>Posti</dt><dd>{{ $elezione->posti }} — massimo {{ $elezione->max_preferenze }} {{ $elezione->max_preferenze === 1 ? 'preferenza' : 'preferenze' }}</dd>
                    <dt>Ruolo degli eletti</dt><dd>{{ $elezione->ruoloEletti() }} per {{ $elezione->durata_mandato_mesi }} mesi</dd>
                    <dt>Candidature</dt><dd>dal {{ $elezione->candidature_dal->format('d/m/Y') }} al {{ $elezione->candidature_al->format('d/m/Y') }}</dd>
                    @if ($elezione->anzianita_minima_mesi > 0)<dt>Anzianità minima</dt><dd>{{ $elezione->anzianita_minima_mesi }} mesi di iscrizione</dd>@endif
                    <dt>Parità</dt><dd>{{ \App\Models\Elezione::REGOLE_PARITA[$elezione->regola_parita] ?? $elezione->regola_parita }}</dd>
                    <dt>Voto</dt><dd>{{ $riunione->voto_online ? 'Online dall\'area soci e in sala' : 'Solo con scheda cartacea in sala' }}</dd>
                </dl>
            </div>
        </div>

        @if ($elezione->stato === 'candidature')
            <form method="POST" action="{{ route('elezioni.apri-voto', $elezione) }}" onsubmit="return confirm('Aprire il voto? Le candidature verranno chiuse.');">
                @csrf
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-down me-1"></i>Apri il voto</button>
                <div class="form-text">Richiede il quorum costitutivo dell'assemblea ({{ $quorum['presenti'] }} presenti con diritto di voto).</div>
            </form>
        @elseif ($elezione->stato === 'scrutinio')
            <form method="POST" action="{{ route('elezioni.proclama', $elezione) }}" onsubmit="return confirm('Proclamare gli eletti? Le cariche uscenti verranno chiuse.');">
                @csrf
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-award me-1"></i>Proclama gli eletti</button>
                <div class="form-text">Chiude le cariche attive ({{ implode(', ', $elezione->ruoliUscenti()) }}) e crea quelle degli eletti.</div>
            </form>
        @elseif ($elezione->stato === 'proclamata')
            <div class="alert alert-success small">Proclamata il {{ $elezione->proclamata_at?->format('d/m/Y H:i') }}. Assegna i ruoli interni dalla <a href="{{ route('organi-sociali.index') }}">pagina degli organi sociali</a>.</div>
        @endif
    </div>
</div>
@endsection
