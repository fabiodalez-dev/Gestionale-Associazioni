@extends('layouts.app')

@section('title', 'Audit accessi - '.session('app_name', config('app.name')))

@section('content')
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="bi bi-shield-lock me-2"></i>Audit accessi</h1>
        <p class="text-muted mb-0">Login, tentativi falliti e logout su tutte le aree (admin, area soci, frontend pubblico).</p>
    </div>
</div>

<form method="GET" action="{{ route('audit-accessi.index') }}" class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
        <div>
            <label for="filtroGuard" class="form-label small text-muted mb-1">Area</label>
            <select name="guard" id="filtroGuard" class="form-select form-select-sm">
                <option value="">Tutte</option>
                @foreach ($guards as $guard)
                    <option value="{{ $guard }}" @selected(($filtri['guard'] ?? '') === $guard)>
                        {{ ['web' => 'Admin', 'socio' => 'Area soci', 'account' => 'Frontend'][$guard] ?? $guard }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtroEsito" class="form-label small text-muted mb-1">Esito</label>
            <select name="esito" id="filtroEsito" class="form-select form-select-sm">
                <option value="">Tutti</option>
                @foreach ($esiti as $esito)
                    <option value="{{ $esito }}" @selected(($filtri['esito'] ?? '') === $esito)>{{ ucfirst($esito) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtroEmail" class="form-label small text-muted mb-1">Email</label>
            <input type="text" name="email" id="filtroEmail" class="form-control form-control-sm"
                   value="{{ $filtri['email'] ?? '' }}" placeholder="Cerca email...">
        </div>
        <div>
            <label for="filtroDal" class="form-label small text-muted mb-1">Dal</label>
            <input type="date" name="dal" id="filtroDal" class="form-control form-control-sm" value="{{ $filtri['dal'] ?? '' }}">
        </div>
        <div>
            <label for="filtroAl" class="form-label small text-muted mb-1">Al</label>
            <input type="date" name="al" id="filtroAl" class="form-control form-control-sm" value="{{ $filtri['al'] ?? '' }}">
        </div>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtra</button>
        @if (array_filter($filtri))
            <a href="{{ route('audit-accessi.index') }}" class="btn btn-sm btn-outline-secondary">Azzera</a>
        @endif
    </div>
</form>

<div class="card">
    <div class="card-header">{{ $accessi->total() }} {{ $accessi->total() === 1 ? 'accesso registrato' : 'accessi registrati' }}</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Data e ora</th>
                    <th>Area</th>
                    <th>Esito</th>
                    <th>Email</th>
                    <th>Utente</th>
                    <th>IP</th>
                    <th>User agent</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accessi as $accesso)
                    <tr>
                        <td class="text-nowrap">{{ $accesso->created_at?->format('d/m/Y H:i:s') }}</td>
                        <td>{{ ['web' => 'Admin', 'socio' => 'Area soci', 'account' => 'Frontend'][$accesso->guard] ?? $accesso->guard }}</td>
                        <td>
                            @if ($accesso->esito === \App\Models\Accesso::ESITO_LOGIN)
                                <span class="badge bg-success">Login</span>
                            @elseif ($accesso->esito === \App\Models\Accesso::ESITO_FALLITO)
                                <span class="badge bg-danger">Fallito</span>
                            @else
                                <span class="badge bg-secondary">Logout</span>
                            @endif
                        </td>
                        <td>{{ $accesso->email_tentata ?? '—' }}</td>
                        <td>
                            @php $autenticabile = $accesso->autenticabile; @endphp
                            @if ($autenticabile !== null)
                                {{ $autenticabile->nome_completo ?? trim(($autenticabile->nome ?? '').' '.($autenticabile->cognome ?? '')) ?: ($autenticabile->nome ?? '—') }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $accesso->ip ?? '—' }}</td>
                        <td><span class="text-muted small" title="{{ $accesso->user_agent }}">{{ \Illuminate\Support\Str::limit($accesso->user_agent ?? '—', 60) }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-shield-lock fs-3 d-block mb-2"></i>Nessun accesso registrato con questi filtri.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($accessi->hasPages())
        <div class="card-footer">
            {{ $accessi->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>
@endsection
