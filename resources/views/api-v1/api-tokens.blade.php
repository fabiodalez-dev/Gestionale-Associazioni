@extends('layouts.app')

@section('title', 'Token API - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-person-badge me-2"></i>Token API degli utenti</h1>
            <p class="text-muted mb-0">
                @if ($vistaGlobale)
                    Token personali Sanctum di tutti gli utenti, di tutte le associazioni.
                @else
                    Token personali Sanctum degli utenti della tua associazione.
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('api-keys.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-key me-1"></i>Chiavi API
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Utente</th>
                            <th>Nome token</th>
                            <th>Permessi (abilities)</th>
                            <th>Ultimo utilizzo</th>
                            <th>Scadenza</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tokens as $token)
                            <tr>
                                <td>
                                    @if ($token->tokenable)
                                        <div class="fw-semibold">{{ $token->tokenable->nome_completo }}</div>
                                        <small class="text-muted">{{ $token->tokenable->email }}</small>
                                    @else
                                        <span class="text-muted">Utente eliminato</span>
                                    @endif
                                </td>
                                <td class="fw-semibold">{{ $token->name }}</td>
                                <td>
                                    @foreach ($token->abilities ?? [] as $ability)
                                        <span class="badge bg-secondary">{{ $ability }}</span>
                                    @endforeach
                                </td>
                                <td>{{ $token->last_used_at?->format('d/m/Y H:i') ?? 'Mai' }}</td>
                                <td>
                                    @if ($token->expires_at)
                                        <span class="{{ $token->expires_at->isPast() ? 'text-danger fw-semibold' : '' }}">{{ $token->expires_at->format('d/m/Y') }}</span>
                                    @else
                                        Nessuna
                                    @endif
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('api-tokens.destroy', $token->id) }}" class="d-inline"
                                          data-conferma="Revocare questo token? Il client che lo usa smetterà subito di funzionare.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Revoca">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">Nessun token attivo.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
