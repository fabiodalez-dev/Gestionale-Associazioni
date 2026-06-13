{{-- f.1/f.2/f.5 piano "Organizzazione 3": profilo volontario nella scheda socio.
     Sezione disponibilità + mansioni (modificabile dall'admin) e totale ore. --}}
@php
    $vtGiorni = \App\Services\VolontariTurni\Disponibilita::GIORNI;
    $vtFasce = \App\Services\VolontariTurni\Disponibilita::FASCE;
    $vtRegistro = app(\App\Services\VolontariTurni\RegistroOreService::class);
    $vtOreTotali = $vtRegistro->totaleOreSocio($socio);
    $vtOreAnno = $vtRegistro->totaleOreSocio($socio, (int) now()->year);
    $vtMansioniTutte = \App\Models\Mansione::query()->orderBy('nome')->get(['id', 'nome']);
    $vtMansioniSocio = $socio->mansioni->pluck('id')->all();
    $vtDisp = (array) $socio->disponibilita;
@endphp

<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card text-center"><div class="card-body py-2">
        <div class="h4 mb-0">{{ rtrim(rtrim(number_format($vtOreAnno, 2, ',', '.'), '0'), ',') }} h</div>
        <small class="text-muted">Ore volontariato {{ now()->year }}</small>
    </div></div></div>
    <div class="col-md-6"><div class="card text-center"><div class="card-body py-2">
        <div class="h4 mb-0">{{ rtrim(rtrim(number_format($vtOreTotali, 2, ',', '.'), '0'), ',') }} h</div>
        <small class="text-muted">Ore totali</small>
    </div></div></div>
</div>

<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('registro-ore.attestato', [$socio, 'anno' => now()->year]) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-file-earmark-pdf me-1"></i>Attestato {{ now()->year }} (PDF)
    </a>
</div>

<form method="POST" action="{{ route('volontari.profilo.update', $socio) }}">
    @csrf @method('PUT')
    <div class="form-check form-switch mb-3">
        <input type="hidden" name="volontario_attivo" value="0">
        <input class="form-check-input" type="checkbox" id="vt_attivo" name="volontario_attivo" value="1" @checked($socio->volontario_attivo)>
        <label class="form-check-label" for="vt_attivo">Volontario attivo</label>
    </div>

    <h6 class="mb-2">Mansioni / competenze</h6>
    @if ($vtMansioniTutte->isEmpty())
        <p class="text-muted small">Nessuna mansione definita. <a href="{{ route('mansioni.index') }}">Crea le mansioni</a>.</p>
    @else
        <div class="mb-3">
            @foreach ($vtMansioniTutte as $m)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="vt_m_{{ $m->id }}" name="mansioni[]" value="{{ $m->id }}"
                           @checked(in_array($m->id, $vtMansioniSocio, true))>
                    <label class="form-check-label" for="vt_m_{{ $m->id }}">{{ $m->nome }}</label>
                </div>
            @endforeach
        </div>
    @endif

    <h6 class="mb-2">Disponibilità</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th></th>@foreach ($vtFasce as $fk => $fl)<th class="text-center small">{{ $fl }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($vtGiorni as $gk => $gl)
                    <tr>
                        <td class="small fw-semibold">{{ $gl }}</td>
                        @foreach ($vtFasce as $fk => $fl)
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="disponibilita[{{ $gk }}][]" value="{{ $fk }}"
                                       @checked(in_array($fk, (array) ($vtDisp[$gk] ?? []), true))>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="text-end">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva profilo volontario</button>
    </div>
</form>
