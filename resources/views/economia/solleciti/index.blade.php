@extends('layouts.app')

@section('title', 'Solleciti quote')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-bell me-2"></i>Solleciti quote</h1>
    <div class="btn-toolbar gap-2">
        <a href="{{ route('quote.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-credit-card me-1"></i>Tutte le quote
        </a>
    </div>
</div>

<p class="text-muted">
    Quote non ancora pagate: invia un sollecito via email con il link di pagamento online,
    singolarmente o in blocco. Ogni invio è tracciato (conteggio e data ultimo sollecito).
</p>

<form method="GET" action="{{ route('solleciti-quote.index') }}" class="row g-2 mb-3">
    <div class="col-auto">
        <label for="filtroAnno" class="form-label mb-0 small text-muted">Anno</label>
        <select name="anno" id="filtroAnno" class="form-select" onchange="this.form.submit()">
            <option value="Tutti">Tutti</option>
            @foreach ($anni as $anno)
                <option value="{{ $anno }}" @selected((string) $filtroAnno === (string) $anno)>{{ $anno }}</option>
            @endforeach
        </select>
    </div>
</form>

<form method="POST" action="{{ route('solleciti-quote.bulk') }}" id="formBulk"
      onsubmit="return confirm('Inviare il sollecito alle quote selezionate?');">
    @csrf

    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="selezionaTutte">
            <label class="form-check-label small" for="selezionaTutte">Seleziona tutte</label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" id="btnBulk" disabled>
            <i class="bi bi-send me-1"></i>Invia sollecito alle selezionate
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th style="width: 2rem;"></th>
                    <th>Socio</th>
                    <th>Anno</th>
                    <th class="text-end">Importo</th>
                    <th>Scadenza</th>
                    <th class="text-center">Solleciti inviati</th>
                    <th>Ultimo sollecito</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($quote as $quota)
                    @php
                        $statoSollecito = $solleciti[$quota->id] ?? ['conteggio' => 0, 'ultimo' => null];
                        $sollecitabile = $quota->socio !== null && filled($quota->socio->email) && ! $quota->socio->email_opt_out;
                    @endphp
                    <tr>
                        <td>
                            <input class="form-check-input check-quota" type="checkbox" name="quota_ids[]"
                                   value="{{ $quota->id }}" @disabled(! $sollecitabile)>
                        </td>
                        <td>
                            {{ $quota->socio ? trim($quota->socio->cognome.' '.$quota->socio->nome) : '—' }}
                            @unless ($sollecitabile)
                                <span class="badge bg-secondary ms-1"
                                      title="Socio senza email o con opt-out: sollecito non inviabile">no email</span>
                            @endunless
                        </td>
                        <td>{{ $quota->anno }}</td>
                        <td class="text-end">&euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}</td>
                        <td>{{ $quota->data_scadenza?->format('d/m/Y') }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $statoSollecito['conteggio'] > 0 ? 'info' : 'light text-muted' }}">
                                {{ $statoSollecito['conteggio'] }}
                            </span>
                        </td>
                        <td>{{ $statoSollecito['ultimo']?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="text-end">
                            @can('update', $quota)
                                <button type="submit" form="formSingolo{{ $quota->id }}"
                                        class="btn btn-sm btn-outline-primary" @disabled(! $sollecitabile)>
                                    <i class="bi bi-send me-1"></i>Sollecita
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">Nessuna quota da sollecitare. Ottimo!</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</form>

{{-- Form singoli fuori dal form bulk (no form annidati) --}}
@foreach ($quote as $quota)
    <form method="POST" action="{{ route('solleciti-quote.invia', $quota) }}" id="formSingolo{{ $quota->id }}"
          onsubmit="return confirm('Inviare il sollecito a questo socio?');">
        @csrf
    </form>
@endforeach

{{ $quote->links() }}
@endsection

@push('scripts')
<script>
(function () {
    var tutte = document.getElementById('selezionaTutte');
    var btnBulk = document.getElementById('btnBulk');
    var checks = document.querySelectorAll('.check-quota');

    function aggiorna() {
        var selezionate = document.querySelectorAll('.check-quota:checked').length;
        if (btnBulk) btnBulk.disabled = selezionate === 0;
    }

    if (tutte) {
        tutte.addEventListener('change', function () {
            checks.forEach(function (c) { if (!c.disabled) c.checked = tutte.checked; });
            aggiorna();
        });
    }

    checks.forEach(function (c) { c.addEventListener('change', aggiorna); });
})();
</script>
@endpush
