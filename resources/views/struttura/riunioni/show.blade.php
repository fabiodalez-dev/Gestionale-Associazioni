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
                        @if ($descrizione)<div class="text-muted small mb-2">{{ $descrizione }}</div>@endif

                        @if ($votazione)
                            @php
                                $esitoColore = ['approvato' => 'success', 'respinto' => 'danger', 'rinviato' => 'warning'][$votazione->esito] ?? 'secondary';
                            @endphp
                            <div class="alert alert-{{ $esitoColore }} py-2 mb-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <strong>{{ ucfirst($votazione->esito) }}</strong>
                                    — Favorevoli {{ $votazione->favorevoli }}, Contrari {{ $votazione->contrari }}, Astenuti {{ $votazione->astenuti }}
                                    @if ($votazione->note)<div class="small mt-1">{{ $votazione->note }}</div>@endif
                                </span>
                                @if ($riunione->isSvolta())
                                    <button type="button" class="btn btn-sm btn-outline-dark"
                                            data-bs-toggle="modal" data-bs-target="#votazioneModal"
                                            data-punto="{{ $i }}" data-titolo="{{ $titolo }}"
                                            data-esito="{{ $votazione->esito }}" data-fav="{{ $votazione->favorevoli }}"
                                            data-con="{{ $votazione->contrari }}" data-ast="{{ $votazione->astenuti }}"
                                            data-note="{{ $votazione->note }}">Modifica</button>
                                @endif
                            </div>
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
