@extends('area-soci.layouts.app')

@section('title', $riunione->titolo.' - Area Riservata')

@php
    $odg = is_array($riunione->odg) ? $riunione->odg : [];
    $titoloPunto = fn (int $i) => is_array($odg[$i] ?? null) ? ($odg[$i]['punto'] ?? '') : (string) ($odg[$i] ?? '');
    $esitoColore = ['approvato' => 'success', 'respinto' => 'danger', 'rinviato' => 'warning'];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom gap-2">
        <div>
            <a href="{{ route('area-soci.assemblee') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Assemblee</a>
            <h1 class="h2 mb-0">{{ $riunione->titolo }}</h1>
            <div class="text-muted">{{ $riunione->organoLabel() }} del {{ $riunione->data_ora->format('d/m/Y H:i') }}</div>
        </div>
        <a href="{{ route('area-soci.assemblee.show', $riunione) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Aggiorna</a>
    </div>

    <div class="card mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            @if ($partecipante->isPresente())
                <span class="text-success"><i class="bi bi-check-circle me-1"></i>Risulti presente{{ $partecipante->presente_at ? ' dalle '.$partecipante->presente_at->format('H:i') : '' }}.</span>
            @else
                <span class="text-muted">Non risulti ancora presente: la presenza si registra all'ingresso{{ $riunione->partecipazione_remota ? ' oppure qui, se partecipi a distanza' : '' }}.</span>
                @if ($riunione->partecipazione_remota && $riunione->stato === 'pianificata' && ! $riunione->data_ora->isFuture())
                    <form method="POST" action="{{ route('area-soci.assemblee.presenza', $riunione) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-person-check me-1"></i>Partecipo a distanza</button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    @if (! $riunione->voto_online)
        <p class="text-muted">In questa assemblea si vota in sala.</p>
    @elseif ($votazioni->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="bi bi-hourglass display-5 d-block mb-2"></i>
            Nessuna votazione aperta. Quando la presidenza apre il voto su un punto, lo trovi qui: premi "Aggiorna".
        </div>
    @endif

    @foreach ($votazioni as $votazione)
        @php
            $votati = $giaVotati->get($votazione->id, []);
            $daVotare = $rappresentati->except($votati);
        @endphp
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <span class="fw-semibold">{{ $votazione->punto_indice + 1 }}. {{ $titoloPunto($votazione->punto_indice) }}</span>
                <span class="small text-muted">{{ $votazione->segreta ? 'Voto segreto' : 'Voto palese' }}</span>
            </div>
            <div class="card-body">
                @if ($votazione->isAperta())
                    @if ($rappresentati->isEmpty())
                        <p class="text-muted mb-0">Per votare devi risultare presente con diritto di voto.</p>
                    @elseif ($daVotare->isEmpty())
                        <p class="text-success mb-0"><i class="bi bi-check2-circle me-1"></i>Hai votato. Il risultato sarà visibile alla chiusura.</p>
                    @else
                        <form method="POST" action="{{ route('area-soci.assemblee.vota', [$riunione, $votazione]) }}"
                              onsubmit="return confirm('Confermi il voto? Non potrai modificarlo.');">
                            @csrf
                            @foreach ($daVotare as $rappresentatoId => $etichetta)
                                <fieldset class="mb-3">
                                    <legend class="fs-6 fw-semibold">{{ $etichetta }}</legend>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($opzioni as $valore => $label)
                                            <input type="radio" class="btn-check" name="voti[{{ $rappresentatoId }}]" value="{{ $valore }}"
                                                   id="voto-{{ $votazione->id }}-{{ $rappresentatoId }}-{{ $valore }}" required>
                                            <label class="btn btn-outline-primary" for="voto-{{ $votazione->id }}-{{ $rappresentatoId }}-{{ $valore }}">{{ $label }}</label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                            <button type="submit" class="btn btn-primary">Invia il voto</button>
                        </form>
                    @endif
                @else
                    <span class="badge bg-{{ $esitoColore[$votazione->esito] ?? 'secondary' }}">{{ ucfirst($votazione->esito) }}</span>
                    <span class="ms-2 small">Favorevoli {{ $votazione->favorevoli }}, contrari {{ $votazione->contrari }}, astenuti {{ $votazione->astenuti }}</span>
                @endif
            </div>
        </div>
    @endforeach
@endsection
