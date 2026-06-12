@extends('layouts.app')

@section('title', 'Invia Comunicazione — '.session('associazione_nome'))

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/vendor/grapesjs/grapes.min.css') }}">
<style>
    #gjs-editor { border: 1px solid #dee2e6; border-radius: .375rem; min-height: 420px; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-send me-2"></i>Invia Comunicazione</h1>
</div>

@unless ($isConfigured)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        SMTP non configurato: configura le <a href="{{ route('email-impostazioni.index') }}" class="alert-link">impostazioni email</a> prima di inviare comunicazioni.
    </div>
@endunless

<form method="POST" action="{{ route('comunicazioni.store') }}" id="comunicazioneForm">
    @csrf
    <input type="hidden" name="corpo_html" id="corpo_html">

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="template_select">Template</label>
                    <select class="form-select" id="template_select">
                        <option value="">— Comunicazione libera —</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template->codice }}"
                                    data-oggetto="{{ $template->oggetto }}"
                                    data-json="{{ $template->corpo_json }}"
                                    data-html="{{ $template->corpo_html }}">{{ $template->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="subject">Oggetto *</label>
                    <input type="text" class="form-control @error('subject') is-invalid @enderror" id="subject" name="subject" value="{{ old('subject') }}" required maxlength="500">
                    @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row g-3 mt-1 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="recipient_type">Destinatari</label>
                    <select class="form-select" id="recipient_type" name="recipient_type">
                        <option value="all" @selected(old('recipient_type', 'all') === 'all')>Tutti i soci attivi</option>
                        <option value="category" @selected(old('recipient_type') === 'category')>Per categoria socio</option>
                        <option value="section" @selected(old('recipient_type') === 'section')>Per sede</option>
                        <option value="tag" @selected(old('recipient_type') === 'tag')>Per tag</option>
                        <option value="gruppo" @selected(old('recipient_type') === 'gruppo')>Per gruppo dinamico</option>
                    </select>
                </div>
                <div class="col-md-4" id="filterWrap" style="display:none;">
                    <label class="form-label" for="recipient_filter_id">Filtro</label>
                    <select class="form-select @error('recipient_filter_id') is-invalid @enderror" id="recipient_filter_id" name="recipient_filter_id">
                    </select>
                    @error('recipient_filter_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <span class="badge bg-primary fs-6" id="recipientBadge">
                        <i class="bi bi-people me-1"></i><span id="recipientCount">{{ $recipientCount }}</span> destinatari
                    </span>
                </div>
            </div>

            <div class="mt-3">
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-braces me-1"></i>Inserisci Placeholder
                    </button>
                    <ul class="dropdown-menu">
                        @foreach ($placeholders as $p)
                            <li><button class="dropdown-item placeholder-item" type="button" data-placeholder="{{ '{'.$p.'}' }}">{{ '{'.$p.'}' }}</button></li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div id="gjs-editor">{!! old('corpo_html') !!}</div>

    <div class="my-3">
        <button type="submit" class="btn btn-primary" @disabled(! $isConfigured)>
            <i class="bi bi-send me-1"></i>Accoda invio
        </button>
        <a href="{{ route('email-coda.index') }}" class="btn btn-outline-secondary"><i class="bi bi-hourglass-split me-1"></i>Vai alla coda</a>
    </div>
</form>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Invii recenti</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Oggetto</th>
                    <th class="text-center">Totale</th>
                    <th class="text-center">Inviate</th>
                    <th class="text-center">Fallite</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentBatches as $batch)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($batch->prima_data)->format('d/m/Y H:i') }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($batch->oggetto, 60) }}</td>
                        <td class="text-center">{{ $batch->totale }}</td>
                        <td class="text-center"><span class="badge bg-success">{{ $batch->inviate }}</span></td>
                        <td class="text-center"><span class="badge {{ $batch->fallite > 0 ? 'bg-danger' : 'bg-secondary' }}">{{ $batch->fallite }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">Nessun invio recente.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<link href="{{ asset('assets/css/grapes-theme.css') }}" rel="stylesheet">
<script src="{{ asset('assets/vendor/grapesjs/grapes.min.js') }}"></script>
<script src="{{ asset('assets/vendor/grapesjs/grapesjs-preset-newsletter.min.js') }}"></script>
<script>
const filterOptions = {
    category: @json($categorie->map(fn ($c) => ['id' => $c->id, 'label' => $c->nome])->values()),
    section: @json($sedi->map(fn ($s) => ['id' => $s->id, 'label' => $s->nome])->values()),
    tag: @json($tags->map(fn ($t) => ['id' => $t->id, 'label' => $t->nome_tag])->values()),
    gruppo: @json($gruppi->map(fn ($g) => ['id' => $g->id, 'label' => $g->nome_gruppo])->values())
};

const emailCanvasCss = `
    body {
        background: #eef0f3;
        margin: 0;
        padding: 28px 16px;
        font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        color: #24292f;
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
    }
    body > * { max-width: 600px; margin-left: auto; margin-right: auto; }
    h1, h2, h3 { line-height: 1.25; }
    p { margin: 0 0 12px; }
`;
const editor = grapesjs.init({
    container: '#gjs-editor',
    height: '420px',
    storageManager: false,
    canvasCss: emailCanvasCss,
    plugins: ['gjs-preset-newsletter'],
    pluginsOpts: { 'gjs-preset-newsletter': {} },
    assetManager: { upload: false }
});

document.getElementById('comunicazioneForm').addEventListener('submit', function () {
    document.getElementById('corpo_html').value = editor.getHtml() + '<style>' + editor.getCss() + '</style>';
});

// Selezione template: precompila oggetto e contenuto editor
document.getElementById('template_select').addEventListener('change', function () {
    const opt = this.selectedOptions[0];
    if (!opt || !opt.value) return;
    document.getElementById('subject').value = opt.dataset.oggetto || '';
    const json = opt.dataset.json;
    if (json) {
        try { editor.loadProjectData(JSON.parse(json)); return; } catch (e) { /* fallback */ }
    }
    editor.setComponents(opt.dataset.html || '');
});

// Placeholder
document.querySelectorAll('.placeholder-item').forEach(function (item) {
    item.addEventListener('click', function () {
        const selected = editor.getSelected();
        if (selected) { selected.append(this.dataset.placeholder); }
        else { editor.addComponents('<div>' + this.dataset.placeholder + '</div>'); }
    });
});

// Selettore destinatari + conteggio AJAX live
const typeSelect = document.getElementById('recipient_type');
const filterWrap = document.getElementById('filterWrap');
const filterSelect = document.getElementById('recipient_filter_id');

function refreshFilterOptions() {
    const type = typeSelect.value;
    const options = filterOptions[type];
    filterSelect.replaceChildren();
    if (!options) {
        filterWrap.style.display = 'none';
        updateCount();
        return;
    }
    options.forEach(function (o) {
        const el = document.createElement('option');
        el.value = o.id;
        el.textContent = o.label;
        filterSelect.appendChild(el);
    });
    filterWrap.style.display = '';
    updateCount();
}

function updateCount() {
    const params = new URLSearchParams({
        recipient_type: typeSelect.value,
        recipient_filter_id: filterWrap.style.display === 'none' ? '' : (filterSelect.value || '')
    });
    fetch(@json(route('comunicazioni.count')) + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => { if (data.success) document.getElementById('recipientCount').textContent = data.count; })
        .catch(() => {});
}

typeSelect.addEventListener('change', refreshFilterOptions);
filterSelect.addEventListener('change', updateCount);
if (typeSelect.value !== 'all') refreshFilterOptions();
</script>
@endpush
