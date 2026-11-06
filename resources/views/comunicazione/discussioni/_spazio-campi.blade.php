@php /** @var \App\Models\DiscussioneSpazio|null $spazio */ @endphp
<div class="row g-2 mb-2">
    <div class="col-md-6">
        <label class="form-label small" for="nome-{{ $prefisso }}">Nome</label>
        <input type="text" name="nome" id="nome-{{ $prefisso }}" class="form-control form-control-sm" maxlength="100" value="{{ $spazio?->nome }}" required>
    </div>
    <div class="col-md-6">
        <label class="form-label small" for="accesso-{{ $prefisso }}">Chi accede</label>
        <select name="accesso" id="accesso-{{ $prefisso }}" class="form-select form-select-sm">
            @foreach (\App\Models\DiscussioneSpazio::ACCESSI as $valore => $etichetta)
                <option value="{{ $valore }}" @selected(($spazio?->accesso ?? 'tutti') === $valore)>{{ $etichetta }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label small" for="tag-{{ $prefisso }}">Tag (se l'accesso è per tag)</label>
        <select name="tag_id" id="tag-{{ $prefisso }}" class="form-select form-select-sm">
            <option value="">—</option>
            @foreach ($tags as $tag)
                <option value="{{ $tag->id }}" @selected($spazio?->tag_id === $tag->id)>{{ $tag->nome_tag }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label small" for="ordine-{{ $prefisso }}">Ordine</label>
        <input type="number" name="ordine" id="ordine-{{ $prefisso }}" class="form-control form-control-sm" min="0" max="1000" value="{{ $spazio?->ordine ?? 0 }}">
    </div>
    <div class="col-12">
        <label class="form-label small" for="descr-{{ $prefisso }}">Descrizione</label>
        <input type="text" name="descrizione" id="descr-{{ $prefisso }}" class="form-control form-control-sm" maxlength="1000" value="{{ $spazio?->descrizione }}">
    </div>
</div>
