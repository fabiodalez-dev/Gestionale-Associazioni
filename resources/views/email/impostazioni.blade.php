@extends('layouts.app')

@section('title', 'Impostazioni Email — '.session('associazione_nome'))

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-gear me-2"></i>Impostazioni Email</h1>
    @if ($settings?->is_verified)
        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>SMTP verificato</span>
    @elseif ($isConfigured)
        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Configurato, non verificato</span>
    @else
        <span class="badge bg-secondary">Non configurato</span>
    @endif
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-success">{{ $stats['inviate_oggi'] }}</div>
                <div class="text-muted small">Inviate oggi</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-primary">{{ $stats['inviate_ultima_ora'] }}</div>
                <div class="text-muted small">Inviate nell'ultima ora (limite: {{ $settings?->max_per_hour ?? 100 }}/h)</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-danger">{{ $stats['fallite_totali'] }}</div>
                <div class="text-muted small">Fallite (totale)</div>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('email-impostazioni.store') }}">
    @csrf
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-hdd-network me-2"></i>Server SMTP</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="smtp_host">Host SMTP *</label>
                            <input type="text" class="form-control @error('smtp_host') is-invalid @enderror" id="smtp_host" name="smtp_host" value="{{ old('smtp_host', $settings->smtp_host ?? '') }}" required>
                            @error('smtp_host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="smtp_port">Porta *</label>
                            <input type="number" class="form-control @error('smtp_port') is-invalid @enderror" id="smtp_port" name="smtp_port" value="{{ old('smtp_port', $settings->smtp_port ?? 587) }}" required>
                            @error('smtp_port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_user">Utente SMTP</label>
                            <input type="text" class="form-control" id="smtp_user" name="smtp_user" value="{{ old('smtp_user', $settings->smtp_user ?? '') }}" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_pass">Password SMTP {{ $settings ? '' : '*' }}</label>
                            <input type="password" class="form-control @error('smtp_pass') is-invalid @enderror" id="smtp_pass" name="smtp_pass" autocomplete="new-password" placeholder="{{ $settings ? 'Lascia vuoto per non modificare' : '' }}">
                            @error('smtp_pass')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_encryption">Crittografia *</label>
                            <select class="form-select @error('smtp_encryption') is-invalid @enderror" id="smtp_encryption" name="smtp_encryption">
                                @foreach (['tls' => 'TLS (STARTTLS — porta 587)', 'ssl' => 'SSL (SMTPS — porta 465)', 'none' => 'Nessuna'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('smtp_encryption', $settings->smtp_encryption ?? 'tls') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('smtp_encryption')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="max_per_hour">Limite invii orario *</label>
                            <input type="number" min="1" class="form-control @error('max_per_hour') is-invalid @enderror" id="max_per_hour" name="max_per_hour" value="{{ old('max_per_hour', $settings->max_per_hour ?? 100) }}" required>
                            @error('max_per_hour')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Mittente</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="from_email">Email mittente *</label>
                            <input type="email" class="form-control @error('from_email') is-invalid @enderror" id="from_email" name="from_email" value="{{ old('from_email', $settings->from_email ?? '') }}" required>
                            @error('from_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="from_name">Nome mittente</label>
                            <input type="text" class="form-control" id="from_name" name="from_name" value="{{ old('from_name', $settings->from_name ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="reply_to_email">Email di risposta (opzionale)</label>
                            <input type="email" class="form-control @error('reply_to_email') is-invalid @enderror" id="reply_to_email" name="reply_to_email" value="{{ old('reply_to_email', $settings->reply_to_email ?? '') }}">
                            @error('reply_to_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="reply_to_name">Nome di risposta (opzionale)</label>
                            <input type="text" class="form-control" id="reply_to_name" name="reply_to_name" value="{{ old('reply_to_name', $settings->reply_to_name ?? '') }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-magic me-2"></i>Notifiche automatiche</div>
                <div class="card-body">
                    @foreach ([
                        'auto_benvenuto' => ['Email di benvenuto ai nuovi soci', true],
                        'auto_scadenza_tessera' => ['Promemoria scadenza tessera', true],
                        'auto_scadenza_quota' => ['Promemoria quote da pagare', true],
                        'auto_rinnovo_tessera' => ['Conferma rinnovo tessera', false],
                        'auto_pagamento_quota' => ['Conferma pagamento quota', false],
                    ] as $field => [$label, $default])
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="{{ $field }}" name="{{ $field }}" value="1"
                                @checked(old($field, $settings->{$field} ?? $default))>
                            <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-envelope-check me-2"></i>Test SMTP</div>
                <div class="card-body">
                    @if ($settings?->last_test_at)
                        <p class="small text-muted mb-2">Ultimo test riuscito: {{ $settings->last_test_at->format('d/m/Y H:i') }}</p>
                    @endif
                    <div class="input-group">
                        <input type="email" class="form-control" id="test_email" placeholder="destinatario@esempio.it" value="{{ auth()->user()->email }}">
                        <button type="button" class="btn btn-outline-primary" id="btnTestSmtp" @disabled(! $isConfigured)>
                            <i class="bi bi-send me-1"></i>Invia test
                        </button>
                    </div>
                    <div id="testSmtpResult" class="small mt-2"></div>
                    @unless ($isConfigured)
                        <p class="small text-muted mt-2 mb-0">Salva prima la configurazione SMTP per poter inviare un test.</p>
                    @endunless
                </div>
            </div>
        </div>
    </div>

    <div class="mb-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva impostazioni</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
document.getElementById('btnTestSmtp')?.addEventListener('click', function () {
    const btn = this;
    const result = document.getElementById('testSmtpResult');
    const email = document.getElementById('test_email').value;
    btn.disabled = true;
    result.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Invio in corso…</span>';

    fetch(@json(route('email-impostazioni.test')), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        },
        body: JSON.stringify({ test_email: email })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            result.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + (data.message || 'Email di test inviata!') + '</span>';
        } else {
            const err = document.createElement('span');
            err.className = 'text-danger';
            err.textContent = data.error || (data.errors ? Object.values(data.errors).flat().join(' ') : 'Invio fallito.');
            result.replaceChildren(err);
        }
    })
    .catch(() => { result.innerHTML = '<span class="text-danger">Errore di rete.</span>'; })
    .finally(() => { btn.disabled = false; });
});
</script>
@endpush
