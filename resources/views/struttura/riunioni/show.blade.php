@extends('layouts.app')

@section('title', $riunione->titolo)

@php
    $statoBadge = [
        'pianificata' => ['secondary', 'Pianificata'],
        'svolta' => ['success', 'Svolta'],
        'annullata' => ['danger', 'Annullata'],
    ];
    [$colore, $etichetta] = $statoBadge[$riunione->stato] ?? ['secondary', $riunione->stato];
    $odg = is_array($riunione->odg) ? $riunione->odg : [];
@endphp

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <a href="{{ route('riunioni.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Riunioni</a>
        <h1 class="h2 mb-0">{{ $riunione->titolo }} <span class="badge bg-{{ $colore }} align-middle">{{ $etichetta }}</span></h1>
        <div class="text-muted">{{ $riunione->organoLabel() }}</div>
    </div>
    <div class="btn-toolbar gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#riunioneEditModal">
            <i class="bi bi-pencil me-1"></i>Modifica
        </button>
        <form method="POST" action="{{ route('riunioni.destroy', $riunione) }}" onsubmit="return confirm('Eliminare la riunione?');">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Elimina</button>
        </form>
    </div>
</div>

@include('partials.flash')

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Dati riunione --}}
        <div class="card mb-4">
            <div class="card-body">
                <dl class="row mb-0">
                    @if ($riunione->isAssemblea())
                        <dt class="col-sm-3">Prima convocazione</dt><dd class="col-sm-9">{{ $riunione->data_ora?->format('d/m/Y H:i') }}</dd>
                        <dt class="col-sm-3">Seconda convocazione</dt><dd class="col-sm-9">{{ $riunione->seconda_convocazione_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                        <dt class="col-sm-3">Aventi diritto</dt>
                        <dd class="col-sm-9">
                            Soci attivi{{ $riunione->requisito_quota_pagata ? ' con la quota '.$riunione->data_ora->year.' pagata' : '' }}{{ $riunione->escludi_minorenni_voto ? '; i minorenni partecipano senza voto' : '' }}.
                        </dd>
                    @else
                        <dt class="col-sm-3">Data e ora</dt><dd class="col-sm-9">{{ $riunione->data_ora?->format('d/m/Y H:i') }}</dd>
                    @endif
                    <dt class="col-sm-3">Luogo</dt><dd class="col-sm-9">{{ $riunione->luogo ?? $riunione->spazio?->nome ?? '—' }}</dd>
                    <dt class="col-sm-3">Convocazione</dt>
                    <dd class="col-sm-9">
                        @if ($riunione->convocazione_inviata_at)
                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>Inviata il {{ $riunione->convocazione_inviata_at->format('d/m/Y H:i') }}</span>
                        @else
                            <span class="text-muted">Non ancora inviata</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>

        {{-- Ordine del giorno + votazioni --}}
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ol me-2"></i>Ordine del giorno</span>
            </div>
            <div class="card-body">
                @forelse ($odg as $i => $punto)
                    @php
                        $titolo = is_array($punto) ? ($punto['punto'] ?? '') : $punto;
                        $descrizione = is_array($punto) ? ($punto['descrizione'] ?? '') : '';
                        $votazione = $votazioniPerPunto->get($i);
                    @endphp
                    <div class="border-bottom pb-3 mb-3">
                        <div class="fw-semibold">{{ $i + 1 }}. {{ $titolo }}</div>
                        @if ($riunione->isAssemblea() && $riunione->maggioranzaPunto($i) !== 'semplice')
                            <div class="small text-muted">Maggioranza {{ mb_strtolower(\App\Models\Riunione::MAGGIORANZE[$riunione->maggioranzaPunto($i)]) }}</div>
                        @endif

                        {{-- Conflitti di interesse sul punto (issue #11) --}}
                        @php $conflittiPunto = $riunione->conflitti->where('punto_indice', $i); @endphp
                        <div class="small mb-2">
                            @foreach ($conflittiPunto as $conflitto)
                                <span class="badge text-bg-light border me-1">
                                    <i class="bi bi-shield-exclamation me-1"></i>{{ $conflitto->socio?->nome_completo }} in conflitto
                                    @if ($riunione->stato !== 'annullata')
                                        <form method="POST" action="{{ route('riunioni.conflitti.destroy', [$riunione, $conflitto]) }}" class="d-inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-link btn-sm p-0 ms-1 align-baseline" title="Rimuovi" aria-label="Rimuovi conflitto"><i class="bi bi-x"></i></button>
                                        </form>
                                    @endif
                                </span>
                            @endforeach
                            @if ($riunione->stato !== 'annullata')
                                <a class="text-decoration-none" data-bs-toggle="collapse" href="#conflitto-{{ $i }}" role="button" aria-expanded="false">Dichiara un conflitto</a>
                                <form method="POST" action="{{ route('riunioni.conflitti.store', $riunione) }}" class="collapse row g-2 mt-1" id="conflitto-{{ $i }}">
                                    @csrf
                                    <input type="hidden" name="punto_indice" value="{{ $i }}">
                                    <div class="col-md-4">
                                        @if ($riunione->isAssemblea())
                                            <input type="text" name="socio" class="form-control form-control-sm" placeholder="N. socio o tessera" maxlength="255" required aria-label="Socio">
                                        @else
                                            <select name="socio" class="form-select form-select-sm" required aria-label="Socio">
                                                @foreach ($riunione->partecipanti->whereNotNull('socio_id') as $p)
                                                    <option value="{{ $p->socio_id }}">{{ $p->nomeCompleto() }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>
                                    <div class="col-md-6"><input type="text" name="motivazione" class="form-control form-control-sm" maxlength="1000" placeholder="Motivazione (facoltativa)" aria-label="Motivazione"></div>
                                    <div class="col-md-2"><button type="submit" class="btn btn-sm btn-outline-primary w-100">Registra</button></div>
                                </form>
                            @endif
                        </div>
                        @if ($descrizione)<div class="text-muted small mb-2">{{ $descrizione }}</div>@endif

                        @if ($votazione && $votazione->isAperta())
                            {{-- Voto online in corso (issue #8) --}}
                            <div class="alert alert-info py-2 mb-0">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                    <span><strong>Voto {{ $votazione->segreta ? 'segreto' : 'palese' }} aperto</strong> dalle {{ $votazione->aperta_at->format('H:i') }} — <span class="tabular">{{ $votazione->votanti_count }}</span> voti ricevuti online</span>
                                </div>
                                <form method="POST" action="{{ route('riunioni.voto-online.chiudi', [$riunione, $votazione]) }}" class="row g-2 align-items-end"
                                      onsubmit="return confirm('Chiudere il voto e calcolare l\'esito?');">
                                    @csrf
                                    <div class="col-12 small">Voti espressi in sala da aggiungere (facoltativo):</div>
                                    <div class="col-4 col-md-3"><input type="number" name="favorevoli" class="form-control form-control-sm" min="0" placeholder="Favorevoli" aria-label="Favorevoli in sala"></div>
                                    <div class="col-4 col-md-3"><input type="number" name="contrari" class="form-control form-control-sm" min="0" placeholder="Contrari" aria-label="Contrari in sala"></div>
                                    <div class="col-4 col-md-3"><input type="number" name="astenuti" class="form-control form-control-sm" min="0" placeholder="Astenuti" aria-label="Astenuti in sala"></div>
                                    <div class="col-12 col-md-3"><button type="submit" class="btn btn-sm btn-primary w-100">Chiudi il voto</button></div>
                                </form>
                            </div>
                        @elseif ($votazione)
                            @php
                                $esitoColore = ['approvato' => 'success', 'respinto' => 'danger', 'rinviato' => 'warning'][$votazione->esito] ?? 'secondary';
                            @endphp
                            <div class="alert alert-{{ $esitoColore }} py-2 mb-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <strong>{{ ucfirst($votazione->esito) }}</strong>
                                    — Favorevoli {{ $votazione->favorevoli }}, Contrari {{ $votazione->contrari }}, Astenuti {{ $votazione->astenuti }}
                                    @if ($votazione->note)<div class="small mt-1">{{ $votazione->note }}</div>@endif
                                    @if ($votazione->isOnline())<div class="small mt-1">Voto online {{ $votazione->segreta ? 'segreto' : 'palese' }}, chiuso alle {{ $votazione->chiusa_at?->format('H:i') }}.</div>@endif
                                </span>
                                @if ($riunione->isSvolta() && ! $votazione->isOnline())
                                    <button type="button" class="btn btn-sm btn-outline-dark"
                                            data-bs-toggle="modal" data-bs-target="#votazioneModal"
                                            data-punto="{{ $i }}" data-titolo="{{ $titolo }}"
                                            data-esito="{{ $votazione->esito }}" data-fav="{{ $votazione->favorevoli }}"
                                            data-con="{{ $votazione->contrari }}" data-ast="{{ $votazione->astenuti }}"
                                            data-note="{{ $votazione->note }}">Modifica</button>
                                @endif
                            </div>
                        @elseif ($riunione->isAssemblea() && $riunione->voto_online && $riunione->stato === 'pianificata')
                            <form method="POST" action="{{ route('riunioni.voto-online.apri', $riunione) }}" class="d-flex align-items-center gap-3 flex-wrap">
                                @csrf
                                <input type="hidden" name="punto_indice" value="{{ $i }}">
                                <div class="form-check mb-0">
                                    <input type="hidden" name="segreta" value="0">
                                    <input class="form-check-input" type="checkbox" name="segreta" value="1" id="segreta-{{ $i }}">
                                    <label class="form-check-label small" for="segreta-{{ $i }}">Voto segreto</label>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-phone me-1"></i>Apri il voto online</button>
                            </form>
                        @elseif ($riunione->isSvolta())
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#votazioneModal"
                                    data-punto="{{ $i }}" data-titolo="{{ $titolo }}">
                                <i class="bi bi-check2-square me-1"></i>Registra votazione
                            </button>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">Nessun punto all'ordine del giorno.</p>
                @endforelse
            </div>
        </div>

        {{-- Deleghe (issue #7) --}}
        @if ($riunione->isAssemblea())
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-lines-fill me-2"></i>Deleghe</span>
                <span class="small text-muted">
                    @if ($riunione->ammetteDeleghe())
                        massimo {{ $riunione->max_deleghe_per_socio }} per socio{{ $riunione->deleghe_vietate_cariche ? ', non a chi ha una carica' : '' }}
                    @else
                        non ammesse
                    @endif
                </span>
            </div>
            @if ($riunione->ammetteDeleghe())
                <ul class="list-group list-group-flush">
                    @forelse ($riunione->deleghe as $delega)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <div><strong>{{ $delega->delegante?->nome_completo }}</strong> delega <strong>{{ $delega->delegato?->nome_completo }}</strong></div>
                                <small class="text-muted">{{ $delega->origine === 'area_soci' ? 'Dall\'area soci' : 'Registrata dalla segreteria' }} il {{ $delega->created_at?->format('d/m/Y H:i') }}</small>
                            </div>
                            @if ($riunione->stato === 'pianificata')
                                <form method="POST" action="{{ route('riunioni.deleghe.destroy', [$riunione, $delega]) }}" onsubmit="return confirm('Revocare la delega?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Revoca"><i class="bi bi-x-lg"></i></button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Nessuna delega registrata.</li>
                    @endforelse
                </ul>
                @if ($riunione->stato === 'pianificata' && $riunione->aventi_diritto_at)
                    <div class="card-body border-top">
                        <form method="POST" action="{{ route('riunioni.deleghe.store', $riunione) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-5">
                                <label class="form-label small" for="delegaDelegante">Delegante</label>
                                <input type="text" name="delegante" id="delegaDelegante" class="form-control form-control-sm" placeholder="N. socio o tessera" maxlength="255" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small" for="delegaDelegato">Delegato</label>
                                <input type="text" name="delegato" id="delegaDelegato" class="form-control form-control-sm" placeholder="N. socio o tessera" maxlength="255" required>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Registra</button>
                            </div>
                        </form>
                        <div class="form-text">Per le deleghe su carta: allega il modulo firmato tra gli allegati della riunione.</div>
                    </div>
                @endif
            @endif
        </div>
        @endif

        {{-- Elezioni (issue #9) --}}
        @if ($riunione->isAssemblea())
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-award me-2"></i>Elezioni</span>
                @if ($riunione->stato === 'pianificata')
                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#nuovaElezione" aria-expanded="false" aria-controls="nuovaElezione">
                        <i class="bi bi-plus-lg me-1"></i>Nuova elezione
                    </button>
                @endif
            </div>
            <ul class="list-group list-group-flush">
                @forelse ($riunione->elezioni as $elezione)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <a href="{{ route('elezioni.show', $elezione) }}" class="fw-semibold text-decoration-none">{{ $elezione->organoLabel() }}</a>
                            <div class="small text-muted">{{ $elezione->posti }} posti · {{ $elezione->candidature_count }} candidature valide</div>
                        </div>
                        <span class="badge bg-secondary">{{ $elezione->statoLabel() }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nessuna elezione in questa assemblea.</li>
                @endforelse
            </ul>
            @if ($riunione->stato === 'pianificata')
                <div class="collapse @if ($errors->hasAny(['organo', 'posti', 'max_preferenze', 'candidature_al'])) show @endif" id="nuovaElezione">
                    <form method="POST" action="{{ route('riunioni.elezioni.store', $riunione) }}" class="card-body border-top row g-2">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label small" for="elOrgano">Organo da eleggere</label>
                            <select name="organo" id="elOrgano" class="form-select form-select-sm">
                                @foreach (\App\Models\Elezione::ORGANI as $valore => $organo)
                                    <option value="{{ $valore }}" @selected(old('organo') === $valore)>{{ $organo['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-3 col-md-2">
                            <label class="form-label small" for="elPosti">Posti</label>
                            <input type="number" name="posti" id="elPosti" min="1" max="50" class="form-control form-control-sm" value="{{ old('posti', 5) }}" required>
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label small" for="elPref">Preferenze</label>
                            <input type="number" name="max_preferenze" id="elPref" min="1" class="form-control form-control-sm" value="{{ old('max_preferenze', 3) }}" required>
                        </div>
                        <div class="col-5 col-md-2">
                            <label class="form-label small" for="elDurata">Mandato (mesi)</label>
                            <input type="number" name="durata_mandato_mesi" id="elDurata" min="1" max="120" class="form-control form-control-sm" value="{{ old('durata_mandato_mesi', 36) }}" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="elDal">Candidature dal</label>
                            <input type="date" name="candidature_dal" id="elDal" class="form-control form-control-sm" value="{{ old('candidature_dal', now()->toDateString()) }}" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="elAl">al</label>
                            <input type="date" name="candidature_al" id="elAl" class="form-control form-control-sm" value="{{ old('candidature_al', $riunione->data_ora->copy()->subDay()->toDateString()) }}" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small" for="elAnz">Anzianità (mesi)</label>
                            <input type="number" name="anzianita_minima_mesi" id="elAnz" min="0" class="form-control form-control-sm" value="{{ old('anzianita_minima_mesi', 0) }}">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label small" for="elParita">In caso di parità</label>
                            <select name="regola_parita" id="elParita" class="form-select form-select-sm">
                                @foreach (\App\Models\Elezione::REGOLE_PARITA as $valore => $etichetta)
                                    <option value="{{ $valore }}" @selected(old('regola_parita') === $valore)>{{ $etichetta }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12"><button type="submit" class="btn btn-sm btn-primary">Crea elezione</button></div>
                    </form>
                </div>
            @endif
        </div>
        @endif

        {{-- Genera bozza verbale --}}
        @if ($riunione->isSvolta())
        <div class="card mb-4">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="mb-1"><i class="bi bi-journal-plus me-2"></i>Verbale</h6>
                    @if ($riunione->verbale)
                        <span class="text-success">Bozza generata: <a href="{{ route('verbali.index', ['edit' => $riunione->verbale_id]) }}">n. {{ $riunione->verbale->numero_completo }}</a></span>
                    @else
                        <span class="text-muted small">Genera una bozza di verbale precompilato con presenti, OdG ed esiti.</span>
                    @endif
                </div>
                @unless ($riunione->verbale)
                <form method="POST" action="{{ route('riunioni.genera-verbale', $riunione) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary"><i class="bi bi-journal-plus me-1"></i>Genera bozza verbale</button>
                </form>
                @endunless
            </div>
        </div>
        @endif
    </div>

    {{-- Partecipanti / convocazione --}}
    <div class="col-lg-4">
        @if ($riunione->isAssemblea())
            @include('struttura.riunioni._quorum', ['riunione' => $riunione, 'quorum' => $quorum])

            <div class="card">
                <div class="card-header"><i class="bi bi-people me-2"></i>Aventi diritto</div>
                <div class="card-body">
                    @if ($riunione->aventi_diritto_at)
                        <p class="mb-2"><strong class="tabular">{{ $quorum['aventi_diritto'] }}</strong> soci con diritto di voto, elenco calcolato il {{ $riunione->aventi_diritto_at->format('d/m/Y H:i') }}.</p>
                    @else
                        <p class="text-muted mb-2">L'elenco non è ancora stato calcolato.</p>
                    @endif
                    <div class="d-flex flex-wrap gap-2">
                        @if ($riunione->stato === 'pianificata')
                            <form method="POST" action="{{ route('riunioni.aventi-diritto', $riunione) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-arrow-repeat me-1"></i>{{ $riunione->aventi_diritto_at ? 'Aggiorna elenco' : 'Calcola elenco' }}
                                </button>
                            </form>
                        @endif
                        @if ($riunione->aventi_diritto_at)
                            <a href="{{ route('riunioni.appello', $riunione) }}" class="btn btn-sm btn-primary"><i class="bi bi-person-check me-1"></i>Appello</a>
                        @endif
                        @unless ($riunione->stato === 'annullata')
                            <form method="POST" action="{{ route('riunioni.convoca', $riunione) }}"
                                  onsubmit="return confirm('Inviare la convocazione via email a tutti gli aventi diritto?');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-envelope me-1"></i>{{ $riunione->convocazione_inviata_at ? 'Reinvia convocazione' : 'Convoca' }}
                                </button>
                            </form>
                        @endunless
                    </div>
                </div>
            </div>
        @else
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people me-2"></i>Invitati</span>
                @unless ($riunione->stato === 'annullata')
                <form method="POST" action="{{ route('riunioni.convoca', $riunione) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary" title="Invita gli intestatari delle cariche attive">
                        <i class="bi bi-envelope me-1"></i>{{ $riunione->convocazione_inviata_at ? 'Reinvia' : 'Convoca' }}
                    </button>
                </form>
                @endunless
            </div>
            <ul class="list-group list-group-flush">
                @forelse ($riunione->partecipanti as $p)
                    @php
                        $statoP = ['invitato' => ['secondary','Invitato'], 'confermato' => ['success','Presente'], 'assente' => ['danger','Assente']][$p->stato] ?? ['secondary', $p->stato];
                    @endphp
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div>{{ $p->nomeCompleto() }}</div>
                            @if ($p->carica_ruolo)<small class="text-muted">{{ $p->carica_ruolo }}</small>@endif
                        </div>
                        <form method="POST" action="{{ route('riunioni.partecipanti.presenza', [$riunione, $p]) }}">
                            @csrf @method('PUT')
                            <select name="stato" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
                                <option value="invitato" @selected($p->stato === 'invitato')>Invitato</option>
                                <option value="confermato" @selected($p->stato === 'confermato')>Presente</option>
                                <option value="assente" @selected($p->stato === 'assente')>Assente</option>
                            </select>
                        </form>
                    </li>
                @empty
                    <li class="list-group-item text-muted text-center py-3">Nessun invitato. Usa "Convoca" per invitare gli intestatari delle cariche.</li>
                @endforelse
            </ul>
        </div>
        @endif
    </div>
</div>

<div class="row mt-4"><div class="col-lg-8">
    @include('allegati._elenco', ['tipo' => 'riunioni', 'modello' => $riunione])
</div></div>

{{-- Modal modifica riunione --}}
<div class="modal fade" id="riunioneEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('riunioni.update', $riunione) }}">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Modifica riunione</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    @include('struttura.riunioni._form', ['riunione' => $riunione, 'spazi' => $spazi, 'stati' => $stati])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal votazione (f.18) --}}
@if ($riunione->isSvolta())
<div class="modal fade" id="votazioneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('riunioni.votazioni.store', $riunione) }}">
                @csrf
                <input type="hidden" name="punto_indice" id="vot_punto">
                <div class="modal-header">
                    <h5 class="modal-title">Esito votazione — <span id="vot_titolo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    @if ($riunione->isAssemblea())
                        <div class="alert alert-light border small">
                            L'esito viene calcolato dai voti e dalla maggioranza richiesta dal punto, su {{ $quorum['presenti'] }} presenti con diritto di voto. Scegli "Rinviato" per rinviare il punto.
                        </div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label">Esito <span class="text-danger">*</span></label>
                        <select name="esito" id="vot_esito" class="form-select" required>
                            @foreach ($esiti as $e)
                                <option value="{{ $e }}">{{ ucfirst($e) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label">Favorevoli</label>
                            <input type="number" name="favorevoli" id="vot_fav" class="form-control" min="0" value="0" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Contrari</label>
                            <input type="number" name="contrari" id="vot_con" class="form-control" min="0" value="0" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Astenuti</label>
                            <input type="number" name="astenuti" id="vot_ast" class="form-control" min="0" value="0" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Note</label>
                        <textarea name="note" id="vot_note" class="form-control" rows="2" maxlength="2000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva esito</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('votazioneModal')?.addEventListener('show.bs.modal', (e) => {
    const b = e.relatedTarget; if (!b) return;
    document.getElementById('vot_punto').value = b.dataset.punto ?? '';
    document.getElementById('vot_titolo').textContent = b.dataset.titolo ?? '';
    document.getElementById('vot_esito').value = b.dataset.esito ?? 'approvato';
    document.getElementById('vot_fav').value = b.dataset.fav ?? 0;
    document.getElementById('vot_con').value = b.dataset.con ?? 0;
    document.getElementById('vot_ast').value = b.dataset.ast ?? 0;
    document.getElementById('vot_note').value = b.dataset.note ?? '';
});
</script>
@endif
@endsection
