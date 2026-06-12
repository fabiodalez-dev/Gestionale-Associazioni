@extends('layouts.app')

@section('title', 'Template Email — '.session('associazione_nome'))

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/vendor/grapesjs/grapes.min.css') }}">
<style>
    #gjs-editor { border: 1px solid #dee2e6; border-radius: .375rem; min-height: 480px; }
    .template-tabs .nav-link { white-space: nowrap; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-palette me-2"></i>Template Email</h1>
</div>

<ul class="nav nav-tabs template-tabs flex-nowrap overflow-auto mb-3">
    @foreach ($templates as $template)
        <li class="nav-item">
            <a class="nav-link {{ $current && $template->codice === $current->codice ? 'active' : '' }}"
               href="{{ route('email-templates.index', ['t' => $template->codice]) }}">
                {{ $template->nome }}
                @unless ($template->attivo)<span class="badge bg-secondary ms-1">Off</span>@endunless
            </a>
        </li>
    @endforeach
</ul>

@if ($current)
<form method="POST" action="{{ route('email-templates.update', ['codice' => $current->codice]) }}" id="templateForm">
    @csrf
    @method('PUT')
    <input type="hidden" name="corpo_html" id="corpo_html">
    <input type="hidden" name="corpo_json" id="corpo_json">

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label class="form-label" for="oggetto">Oggetto *</label>
                    <input type="text" class="form-control @error('oggetto') is-invalid @enderror" id="oggetto" name="oggetto"
                           value="{{ old('oggetto', $current->oggetto) }}" required maxlength="500">
                    @error('oggetto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Placeholder validi: @foreach ($placeholders as $p)<code>{{ '{'.$p.'}' }}</code>@if (! $loop->last) @endif @endforeach</div>
                </div>
                <div class="col-md-2">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="attivo" name="attivo" value="1" @checked(old('attivo', $current->attivo))>
                        <label class="form-check-label" for="attivo">Attivo</label>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-braces me-1"></i>Inserisci Placeholder
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @foreach ($placeholders as $p)
                                <li><button class="dropdown-item placeholder-item" type="button" data-placeholder="{{ '{'.$p.'}' }}">{{ '{'.$p.'}' }}</button></li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn btn-outline-secondary" id="btnAnteprima" data-bs-toggle="modal" data-bs-target="#previewModal">
                            <i class="bi bi-eye me-1"></i>Anteprima
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="gjs-editor">{!! old('corpo_html', $current->corpo_html) !!}</div>

    <div class="my-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva template</button>
    </div>
</form>

<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Anteprima — {{ $current->nome }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="previewFrame" sandbox="allow-same-origin" style="width:100%; height:60vh; border:0;"></iframe>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<link href="{{ asset('assets/css/grapes-theme.css') }}" rel="stylesheet">
<script src="{{ asset('assets/vendor/grapesjs/grapes.min.js') }}"></script>
<script src="{{ asset('assets/vendor/grapesjs/grapesjs-preset-newsletter.min.js') }}"></script>
<script>
const corpoJson = @json($current?->corpo_json);
const corpoHtml = @json(old('corpo_html', $current?->corpo_html ?? ''));

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
    height: '560px',
    storageManager: false,
    canvasCss: emailCanvasCss,
    plugins: ['gjs-preset-newsletter'],
    pluginsOpts: { 'gjs-preset-newsletter': {} },
    assetManager: { upload: false }
});

// Caricamento contenuto: prima corpo_json (project data), in fallback corpo_html
editor.on('load', function () {
    if (corpoJson) {
        try {
            editor.loadProjectData(JSON.parse(corpoJson));
            return;
        } catch (e) { /* fallback su HTML */ }
    }
    if (corpoHtml) {
        editor.setComponents(corpoHtml);
    }
});

// Submit: serializza HTML+CSS e project data nei campi nascosti
document.getElementById('templateForm').addEventListener('submit', function () {
    document.getElementById('corpo_html').value = editor.getHtml() + '<style>' + editor.getCss() + '</style>';
    document.getElementById('corpo_json').value = JSON.stringify(editor.getProjectData());
});

// Dropdown placeholder: appende al componente selezionato o al canvas
document.querySelectorAll('.placeholder-item').forEach(function (item) {
    item.addEventListener('click', function () {
        const placeholder = this.dataset.placeholder;
        const selected = editor.getSelected();
        if (selected) {
            selected.append(placeholder);
        } else {
            editor.addComponents('<div>' + placeholder + '</div>');
        }
    });
});

// Anteprima client-side (senza sostituzione placeholder) in iframe sandbox
document.getElementById('btnAnteprima')?.addEventListener('click', function () {
    const frame = document.getElementById('previewFrame');
    const doc = frame.contentDocument || frame.contentWindow.document;
    doc.open();
    doc.write(editor.getHtml() + '<style>' + editor.getCss() + '</style>');
    doc.close();
});
</script>
@endpush
