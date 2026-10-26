@php
    /** @var \App\Models\Riunione|null $riunione */
    $odgValori = old('odg', $riunione?->odg ?? [['punto' => '', 'descrizione' => '']]);
    if (! is_array($odgValori) || $odgValori === []) {
        $odgValori = [['punto' => '', 'descrizione' => '']];
    }
    $organoCorrente = old('organo', $riunione?->organo ?? \App\Models\Riunione::ORGANO_DIRETTIVO);
    $maggioranze = \App\Models\Riunione::MAGGIORANZE;
@endphp

<div class="mb-3">
    <label class="form-label">Organo</label>
    <select name="organo" class="form-select" id="riunioneOrgano">
        @foreach (\App\Models\Riunione::ORGANI as $valore => $etichetta)
            <option value="{{ $valore }}" @selected($organoCorrente === $valore)>{{ $etichetta }}</option>
        @endforeach
    </select>
    <div class="form-text">Per le assemblee dei soci il sistema calcola gli aventi diritto, il quorum e l'esito delle votazioni.</div>
</div>

<div class="mb-3">
    <label class="form-label">Titolo <span class="text-danger">*</span></label>
    <input type="text" name="titolo" class="form-control @error('titolo') is-invalid @enderror"
           value="{{ old('titolo', $riunione?->titolo) }}" maxlength="200" required>
    @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label">Data e ora <span class="text-danger">*</span></label>
        <input type="datetime-local" name="data_ora" class="form-control @error('data_ora') is-invalid @enderror"
               value="{{ old('data_ora', $riunione?->data_ora?->format('Y-m-d\TH:i')) }}" required>
        @error('data_ora')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">Stato</label>
        <select name="stato" class="form-select">
            @foreach ($stati as $s)
                <option value="{{ $s }}" @selected(old('stato', $riunione?->stato ?? 'pianificata') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label">Luogo</label>
        <input type="text" name="luogo" class="form-control" value="{{ old('luogo', $riunione?->luogo) }}" maxlength="200" placeholder="Es. Sede sociale">
    </div>
    <div class="col-md-6">
        <label class="form-label">Spazio (opzionale)</label>
        <select name="spazio_id" class="form-select">
            <option value="">— Nessuno —</option>
            @foreach ($spazi as $spazio)
                <option value="{{ $spazio->id }}" @selected(old('spazio_id', $riunione?->spazio_id) === $spazio->id)>{{ $spazio->nome }}</option>
            @endforeach
        </select>
    </div>
</div>

<fieldset id="assembleaCampi" class="border rounded p-3 mb-3" @if ($organoCorrente === \App\Models\Riunione::ORGANO_DIRETTIVO) hidden @endif>
    <legend class="float-none w-auto px-2 fs-6 mb-0">Assemblea dei soci</legend>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Seconda convocazione</label>
            <input type="datetime-local" name="seconda_convocazione_at" class="form-control @error('seconda_convocazione_at') is-invalid @enderror"
                   value="{{ old('seconda_convocazione_at', $riunione?->seconda_convocazione_at?->format('Y-m-d\TH:i')) }}">
            @error('seconda_convocazione_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6"></div>
        <div class="col-md-6">
            <label class="form-label">Quorum in prima convocazione</label>
            <select name="quorum_prima" class="form-select">
                <option value="">Predefinito per l'organo</option>
                @foreach (\App\Models\Riunione::QUORUM as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected(old('quorum_prima', $riunione?->quorum_prima) === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Quorum in seconda convocazione</label>
            <select name="quorum_seconda" class="form-select">
                <option value="">Predefinito per l'organo</option>
                @foreach (\App\Models\Riunione::QUORUM as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected(old('quorum_seconda', $riunione?->quorum_seconda) === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12">
            <input type="hidden" name="requisito_quota_pagata" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requisito_quota_pagata" value="1" id="reqQuota"
                       @checked(old('requisito_quota_pagata', $riunione?->requisito_quota_pagata ?? false))>
                <label class="form-check-label" for="reqQuota">Hanno diritto solo i soci con la quota dell'anno della riunione pagata</label>
            </div>
            <input type="hidden" name="escludi_minorenni_voto" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="escludi_minorenni_voto" value="1" id="escMinori"
                       @checked(old('escludi_minorenni_voto', $riunione?->escludi_minorenni_voto ?? true))>
                <label class="form-check-label" for="escMinori">I soci minorenni partecipano senza diritto di voto</label>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="maxDeleghe">Deleghe per socio</label>
            <input type="number" name="max_deleghe_per_socio" id="maxDeleghe" class="form-control @error('max_deleghe_per_socio') is-invalid @enderror"
                   min="0" max="20" value="{{ old('max_deleghe_per_socio', $riunione?->max_deleghe_per_socio ?? 1) }}">
            <div class="form-text">Quante deleghe può ricevere ogni socio. 0 = deleghe non ammesse.</div>
            @error('max_deleghe_per_socio')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 d-flex align-items-center">
            <input type="hidden" name="deleghe_vietate_cariche" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="deleghe_vietate_cariche" value="1" id="delegheCariche"
                       @checked(old('deleghe_vietate_cariche', $riunione?->deleghe_vietate_cariche ?? false))>
                <label class="form-check-label" for="delegheCariche">Chi ricopre una carica non può ricevere deleghe</label>
            </div>
        </div>
        <div class="col-12">
            <input type="hidden" name="voto_online" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="voto_online" value="1" id="votoOnline"
                       @checked(old('voto_online', $riunione?->voto_online ?? false))>
                <label class="form-check-label" for="votoOnline">Voto online: i presenti votano dall'area soci, anche dallo smartphone</label>
            </div>
            <input type="hidden" name="partecipazione_remota" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="partecipazione_remota" value="1" id="partecipazioneRemota"
                       @checked(old('partecipazione_remota', $riunione?->partecipazione_remota ?? false))>
                <label class="form-check-label" for="partecipazioneRemota">Partecipazione a distanza: il socio registra la propria presenza dall'area soci</label>
            </div>
        </div>
    </div>
    <div class="form-text mt-2">Predefiniti: ordinaria con maggioranza assoluta in prima e qualunque numero in seconda; straordinaria con tre quarti in prima e maggioranza assoluta in seconda. Adeguali al tuo statuto.</div>
</fieldset>

<div class="mb-2">
    <label class="form-label d-flex justify-content-between align-items-center">
        <span>Ordine del giorno</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="odgAdd"><i class="bi bi-plus-lg"></i> Aggiungi punto</button>
    </label>
    <div id="odgRows">
        @foreach ($odgValori as $i => $riga)
            <div class="card mb-2 odg-row">
                <div class="card-body py-2">
                    <div class="d-flex gap-2 align-items-start">
                        <div class="flex-grow-1">
                            <input type="text" name="odg[{{ $i }}][punto]" class="form-control form-control-sm mb-1 odg-punto"
                                   placeholder="Titolo del punto" value="{{ is_array($riga) ? ($riga['punto'] ?? '') : '' }}" maxlength="200">
                            <textarea name="odg[{{ $i }}][descrizione]" class="form-control form-control-sm odg-descrizione"
                                      rows="1" placeholder="Descrizione (opzionale)" maxlength="1000">{{ is_array($riga) ? ($riga['descrizione'] ?? '') : '' }}</textarea>
                            @if (is_array($riga) && filled($riga['proposta_id'] ?? null))
                                <input type="hidden" name="odg[{{ $i }}][proposta_id]" class="odg-proposta" value="{{ $riga['proposta_id'] }}">
                                <div class="small text-muted mb-1"><i class="bi bi-lightbulb me-1"></i>Proposta dei soci</div>
                            @endif
                            <select name="odg[{{ $i }}][maggioranza]" class="form-select form-select-sm mt-1 odg-maggioranza" aria-label="Maggioranza richiesta">
                                @foreach ($maggioranze as $valore => $etichetta)
                                    <option value="{{ $valore }}" @selected((is_array($riga) ? ($riga['maggioranza'] ?? 'semplice') : 'semplice') === $valore)>Maggioranza {{ mb_strtolower($etichetta) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger odg-remove" title="Rimuovi"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @error('odg.*.punto')<div class="text-danger small">{{ $message }}</div>@enderror
</div>

<template id="odgTemplate">
    <div class="card mb-2 odg-row">
        <div class="card-body py-2">
            <div class="d-flex gap-2 align-items-start">
                <div class="flex-grow-1">
                    <input type="text" class="form-control form-control-sm mb-1 odg-punto" placeholder="Titolo del punto" maxlength="200">
                    <textarea class="form-control form-control-sm odg-descrizione" rows="1" placeholder="Descrizione (opzionale)" maxlength="1000"></textarea>
                    <select class="form-select form-select-sm mt-1 odg-maggioranza" aria-label="Maggioranza richiesta">
                        @foreach ($maggioranze as $valore => $etichetta)
                            <option value="{{ $valore }}">Maggioranza {{ mb_strtolower($etichetta) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger odg-remove" title="Rimuovi"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    const rows = document.getElementById('odgRows');
    const tpl = document.getElementById('odgTemplate');
    if (!rows || !tpl) return;

    // I campi dell'assemblea compaiono solo per gli organi assembleari
    const organo = document.getElementById('riunioneOrgano');
    const campiAssemblea = document.getElementById('assembleaCampi');
    function aggiornaOrgano() {
        const assemblea = organo && organo.value !== 'direttivo';
        if (campiAssemblea) campiAssemblea.hidden = !assemblea;
        rows.querySelectorAll('.odg-maggioranza').forEach(el => el.hidden = !assemblea);
    }
    organo?.addEventListener('change', aggiornaOrgano);
    document.getElementById('odgAdd')?.addEventListener('click', () => setTimeout(aggiornaOrgano));
    aggiornaOrgano();

    function reindex() {
        rows.querySelectorAll('.odg-row').forEach((row, idx) => {
            const punto = row.querySelector('.odg-punto');
            const descr = row.querySelector('.odg-descrizione');
            if (punto) punto.name = `odg[${idx}][punto]`;
            if (descr) descr.name = `odg[${idx}][descrizione]`;
            const magg = row.querySelector('.odg-maggioranza');
            if (magg) magg.name = `odg[${idx}][maggioranza]`;
            const prop = row.querySelector('.odg-proposta');
            if (prop) prop.name = `odg[${idx}][proposta_id]`;
        });
    }

    document.getElementById('odgAdd')?.addEventListener('click', () => {
        const node = tpl.content.cloneNode(true);
        rows.appendChild(node);
        reindex();
    });

    rows.addEventListener('click', (e) => {
        const btn = e.target.closest('.odg-remove');
        if (!btn) return;
        const allRows = rows.querySelectorAll('.odg-row');
        if (allRows.length <= 1) {
            const row = btn.closest('.odg-row');
            row.querySelectorAll('input, textarea').forEach(el => el.value = '');
            row.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
        } else {
            btn.closest('.odg-row')?.remove();
        }
        reindex();
    });
})();
</script>
