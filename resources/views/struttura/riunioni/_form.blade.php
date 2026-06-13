@php
    /** @var \App\Models\Riunione|null $riunione */
    $odgValori = old('odg', $riunione?->odg ?? [['punto' => '', 'descrizione' => '']]);
    if (! is_array($odgValori) || $odgValori === []) {
        $odgValori = [['punto' => '', 'descrizione' => '']];
    }
@endphp

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

    function reindex() {
        rows.querySelectorAll('.odg-row').forEach((row, idx) => {
            const punto = row.querySelector('.odg-punto');
            const descr = row.querySelector('.odg-descrizione');
            if (punto) punto.name = `odg[${idx}][punto]`;
            if (descr) descr.name = `odg[${idx}][descrizione]`;
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
        } else {
            btn.closest('.odg-row')?.remove();
        }
        reindex();
    });
})();
</script>
