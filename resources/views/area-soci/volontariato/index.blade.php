@extends('area-soci.layouts.app')

@section('title', 'Volontariato - '.$socio->nome_completo)

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-person-raised-hand me-2"></i>Volontariato</h1>
        <div>
            <span class="badge bg-success fs-6">Ore {{ now()->year }}: {{ rtrim(rtrim(number_format($oreAnno, 2, ',', '.'), '0'), ',') }}</span>
            <span class="badge bg-secondary fs-6">Totale: {{ rtrim(rtrim(number_format($oreTotali, 2, ',', '.'), '0'), ',') }} h</span>
        </div>
    </div>

    <div class="row g-4">
        {{-- f.1 Disponibilità --}}
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-calendar-week me-2"></i>La mia disponibilità</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('area-soci.volontariato.disponibilita') }}">
                        @csrf @method('PATCH')
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="volontario_attivo" value="0">
                            <input class="form-check-input" type="checkbox" id="vol_attivo" name="volontario_attivo" value="1"
                                   @checked($socio->volontario_attivo)>
                            <label class="form-check-label" for="vol_attivo">Sono disponibile come volontario/a</label>
                        </div>

                        <p class="small text-muted mb-2">Seleziona i momenti in cui sei generalmente disponibile:</p>
                        <table class="table table-sm align-middle">
                            <thead><tr><th></th>@foreach ($fasce as $fk => $fl)<th class="text-center small">{{ $fl }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($giorni as $gk => $gl)
                                    <tr>
                                        <td class="small fw-semibold">{{ $gl }}</td>
                                        @foreach ($fasce as $fk => $fl)
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" name="disponibilita[{{ $gk }}][]" value="{{ $fk }}"
                                                       @checked(in_array($fk, (array) ($socio->disponibilita[$gk] ?? []), true))>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Salva disponibilità</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- f.4 I miei turni + turni aperti --}}
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-list-check me-2"></i>I miei turni</div>
                <div class="card-body">
                    @if ($mieAssegnazioni->isEmpty())
                        <p class="text-muted mb-0">Non sei iscritto/a a nessun turno.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($mieAssegnazioni as $a)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    <span>
                                        <strong>{{ $a->turno?->titolo }}</strong><br>
                                        <span class="small text-muted">{{ $a->turno?->data?->format('d/m/Y') }} · {{ substr((string) $a->turno?->ora_inizio, 0, 5) }}–{{ substr((string) $a->turno?->ora_fine, 0, 5) }}</span>
                                        @if ($a->isConfermata())
                                            <span class="badge bg-success">Confermato</span>
                                        @else
                                            <span class="badge bg-warning text-dark">In attesa</span>
                                        @endif
                                    </span>
                                    <form method="POST" action="{{ route('area-soci.volontariato.rinuncia', $a) }}"
                                          onsubmit="return confirm('Rinunciare a questo turno?');">
                                        @csrf
                                        <input type="text" name="motivo" class="form-control form-control-sm d-inline-block mb-1" style="max-width:9rem" placeholder="Motivo (facolt.)" maxlength="500">
                                        <button class="btn btn-sm btn-outline-danger" title="Rinuncia"><i class="bi bi-x-circle"></i></button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-megaphone me-2"></i>Turni aperti</div>
                <div class="card-body">
                    @if ($turniAperti->isEmpty())
                        <p class="text-muted mb-0">Al momento non ci sono turni aperti compatibili.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($turniAperti as $t)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    <span>
                                        <strong>{{ $t->titolo }}</strong>
                                        @if ($t->mansione)<span class="badge bg-secondary">{{ $t->mansione->nome }}</span>@endif<br>
                                        <span class="small text-muted">
                                            {{ $t->data->format('d/m/Y') }} · {{ substr((string) $t->ora_inizio, 0, 5) }}–{{ substr((string) $t->ora_fine, 0, 5) }}
                                            @if ($t->spazio) · {{ $t->spazio->nome }}@endif
                                            · {{ $t->postiDisponibili() }} posti liberi
                                        </span>
                                    </span>
                                    <form method="POST" action="{{ route('area-soci.volontariato.candidati', $t) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-primary"><i class="bi bi-hand-thumbs-up me-1"></i>Mi offro</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
