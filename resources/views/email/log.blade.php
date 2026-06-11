@extends('layouts.app')

@section('title', 'Storico Email — '.session('associazione_nome'))

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-clock-history me-2"></i>Storico Email</h1>
    <span class="badge bg-secondary fs-6">{{ $logs->total() }} risultati</span>
</div>

<form method="GET" action="{{ route('email-log.index') }}" class="card mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small" for="f_status">Stato</label>
                <select class="form-select form-select-sm" id="f_status" name="status">
                    <option value="">Tutti</option>
                    <option value="sent" @selected(($filters['status'] ?? '') === 'sent')>Inviata</option>
                    <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>Fallita</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="f_template">Template</label>
                <select class="form-select form-select-sm" id="f_template" name="template">
                    <option value="">Tutti</option>
                    @foreach ($templateCodes as $code)
                        <option value="{{ $code }}" @selected(($filters['template'] ?? '') === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="f_q">Ricerca (email, nome, oggetto)</label>
                <input type="text" class="form-control form-control-sm" id="f_q" name="q" value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="f_from">Dal</label>
                <input type="date" class="form-control form-control-sm" id="f_from" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="f_to">Al</label>
                <input type="date" class="form-control form-control-sm" id="f_to" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button>
            </div>
        </div>
    </div>
</form>

<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Destinatario</th>
                    <th>Oggetto</th>
                    <th>Template</th>
                    <th>Stato</th>
                    <th>Inviata da</th>
                    <th class="text-end">Dettaglio</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ $log->sent_at?->format('d/m/Y H:i') }}</td>
                        <td>
                            {{ $log->to_email }}
                            @if ($log->socio)
                                <div class="small text-muted">{{ $log->socio->nome }} {{ $log->socio->cognome }}</div>
                            @endif
                        </td>
                        <td>{{ \Illuminate\Support\Str::limit($log->subject, 50) }}</td>
                        <td>
                            @if ($log->template_codice)
                                <span class="badge bg-light text-dark border">{{ $log->template_codice }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $log->status === 'sent' ? 'bg-success' : 'bg-danger' }}">{{ $log->status === 'sent' ? 'Inviata' : 'Fallita' }}</span>
                            @if ($log->error_message)
                                <div class="small text-danger">{{ \Illuminate\Support\Str::limit($log->error_message, 60) }}</div>
                            @endif
                        </td>
                        <td>{{ $log->sentBy?->nome_completo ?? 'Sistema' }}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-detail" data-url="{{ route('email-log.show', $log) }}" data-bs-toggle="modal" data-bs-target="#detailModal">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">Nessuna email nello storico.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($logs->hasPages())
        <div class="card-footer">{{ $logs->links() }}</div>
    @endif
</div>

<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dettaglio email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <dl class="row small" id="detailMeta"></dl>
                <iframe id="detailFrame" sandbox="" style="width:100%; height:50vh; border:1px solid #dee2e6; border-radius:.375rem;"></iframe>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.btn-detail').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const meta = document.getElementById('detailMeta');
        const frame = document.getElementById('detailFrame');
        meta.replaceChildren();
        frame.removeAttribute('srcdoc');

        fetch(this.dataset.url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const log = data.log;
                const rows = [
                    ['Destinatario', log.to_name ? (log.to_email + ' (' + log.to_name + ')') : log.to_email],
                    ['Oggetto', log.subject],
                    ['Template', log.template_codice || '—'],
                    ['Stato', log.status === 'sent' ? 'Inviata' : 'Fallita'],
                    ['Data', log.sent_at || '—'],
                    ['Inviata da', log.sent_by],
                    ['Errore', log.error_message || '—']
                ];
                rows.forEach(function ([label, value]) {
                    const dt = document.createElement('dt');
                    dt.className = 'col-sm-3';
                    dt.textContent = label;
                    const dd = document.createElement('dd');
                    dd.className = 'col-sm-9';
                    dd.textContent = value;
                    meta.append(dt, dd);
                });
                frame.srcdoc = log.body_html || '<p style="font-family:sans-serif;color:#888;">Corpo non disponibile.</p>';
            })
            .catch(() => {});
    });
});
</script>
@endpush
