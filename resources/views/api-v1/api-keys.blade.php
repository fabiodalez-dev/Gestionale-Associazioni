@extends('layouts.app')

@section('title', 'Chiavi API - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-key me-2"></i>Chiavi API</h1>
            <p class="text-muted mb-0">Accesso alla REST API v1 ({{ url('/api/v1') }})</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('api-docs.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-book me-1"></i>Documentazione
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#apiKeyModal">
                <i class="bi bi-plus-lg me-1"></i>Nuova Chiave
            </button>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $errore)
                    <li>{{ $errore }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Chiave/token in chiaro: mostrati UNA SOLA volta dopo la creazione --}}
    @if (session('nuova_chiave'))
        <div class="alert alert-warning" role="alert">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>Copia ora la chiave: non sarà più visibile per intero.</strong>
            <div class="input-group mt-2">
                <input type="text" class="form-control font-monospace" value="{{ session('nuova_chiave') }}" readonly id="nuovaChiaveInput">
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('nuovaChiaveInput').value)">
                    <i class="bi bi-clipboard"></i> Copia
                </button>
            </div>
        </div>
    @endif

    @if (session('nuovo_token'))
        <div class="alert alert-warning" role="alert">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>Copia ora il token: non sarà più visibile.</strong>
            <div class="input-group mt-2">
                <input type="text" class="form-control font-monospace" value="{{ session('nuovo_token') }}" readonly id="nuovoTokenInput">
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('nuovoTokenInput').value)">
                    <i class="bi bi-clipboard"></i> Copia
                </button>
            </div>
        </div>
    @endif

    @if ($vistaGlobale)
        <div class="alert alert-info" role="alert">
            <i class="bi bi-globe2 me-1"></i>Stai vedendo le chiavi di <strong>tutte le associazioni</strong> (nessuna associazione selezionata).
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nome</th>
                            <th>Chiave</th>
                            <th>Ambito</th>
                            <th>Permessi</th>
                            <th>Stato</th>
                            <th>Ultimo utilizzo</th>
                            <th>Scadenza</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($chiavi as $chiave)
                            <tr>
                                <td class="fw-semibold">{{ $chiave->nome }}</td>
                                <td>
                                    <code>{{ substr($chiave->api_key, 0, 8) }}...{{ substr($chiave->api_key, -4) }}</code>
                                </td>
                                <td>
                                    @if ($chiave->isGlobale())
                                        <span class="badge bg-warning text-dark"><i class="bi bi-globe2 me-1"></i>Globale</span>
                                    @else
                                        {{ $chiave->associazione?->nome ?? '—' }}
                                    @endif
                                </td>
                                <td>
                                    @foreach ($chiave->permessi ?? [] as $permesso)
                                        <span class="badge bg-secondary">{{ $permesso }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    @if ($chiave->attiva)
                                        <span class="badge bg-success">Attiva</span>
                                    @else
                                        <span class="badge bg-danger">Disattivata</span>
                                    @endif
                                </td>
                                <td>{{ $chiave->ultimo_utilizzo?->format('d/m/Y H:i') ?? 'Mai' }}</td>
                                <td>
                                    @if ($chiave->scadenza)
                                        <span class="{{ $chiave->scadenza->lt(now()->startOfDay()) ? 'text-danger fw-semibold' : '' }}">
                                            {{ $chiave->scadenza->format('d/m/Y') }}
                                        </span>
                                    @else
                                        Nessuna
                                    @endif
                                </td>
                                <td class="text-end">
                                    @can('update', $chiave)
                                        <form method="POST" action="{{ route('api-keys.toggle', $chiave) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="{{ $chiave->attiva ? 'Disattiva' : 'Attiva' }}">
                                                <i class="bi {{ $chiave->attiva ? 'bi-pause-fill' : 'bi-play-fill' }}"></i>
                                            </button>
                                        </form>
                                    @endcan
                                    @can('delete', $chiave)
                                        <form method="POST" action="{{ route('api-keys.destroy', $chiave) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare definitivamente questa chiave API? L\'azione è irreversibile.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">Nessuna chiave API. Creane una con "Nuova Chiave".</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Token personali Sanctum (miglioramento 23) --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-person-badge me-1"></i>I miei token personali (Sanctum)</span>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#apiTokenModal">
                <i class="bi bi-plus-lg me-1"></i>Nuovo Token
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nome</th>
                            <th>Permessi (abilities)</th>
                            <th>Ultimo utilizzo</th>
                            <th>Scadenza</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tokens as $token)
                            <tr>
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
                                          onsubmit="return confirm('Revocare questo token?');">
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
                                <td colspan="5" class="text-center text-muted py-4">Nessun token personale.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modal creazione chiave API --}}
    <div class="modal fade" id="apiKeyModal" tabindex="-1" aria-labelledby="apiKeyModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('api-keys.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="apiKeyModalLabel">Nuova Chiave API</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="apiKeyNome" class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="apiKeyNome" name="nome" value="{{ old('nome') }}" required maxlength="255"
                               placeholder="Es. App check-in eventi">
                    </div>

                    @if (auth()->user()->isSuperAdmin())
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="apiKeyGlobale" name="globale" value="1">
                            <label class="form-check-label" for="apiKeyGlobale">Chiave Globale (tutte le associazioni)</label>
                        </div>
                        <div class="alert alert-warning d-none" id="apiKeyGlobaleWarning">
                            <i class="bi bi-exclamation-triangle me-1"></i>Una chiave globale accede ai dati di <strong>tutte</strong> le associazioni. Usala solo per integrazioni di sistema.
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label">Permessi</label>
                        @foreach ($permessiDisponibili as $permesso)
                            <div class="form-check">
                                <input class="form-check-input permesso-check" type="checkbox" name="permessi[]"
                                       value="{{ $permesso }}" id="permesso-{{ $loop->index }}"
                                       @checked($permesso === 'tessere:read')>
                                <label class="form-check-label" for="permesso-{{ $loop->index }}">
                                    <code>{{ $permesso }}</code>
                                    @if ($permesso === '*')
                                        <span class="text-muted">(accesso totale)</span>
                                    @endif
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-3">
                        <label for="apiKeyScadenza" class="form-label">Scadenza (opzionale)</label>
                        <input type="date" class="form-control" id="apiKeyScadenza" name="scadenza" value="{{ old('scadenza') }}">
                        <div class="form-text">La chiave resta valida per tutto il giorno indicato.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Crea Chiave</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal creazione token Sanctum --}}
    <div class="modal fade" id="apiTokenModal" tabindex="-1" aria-labelledby="apiTokenModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('api-tokens.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="apiTokenModalLabel">Nuovo Token Personale</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="apiTokenNome" class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="apiTokenNome" name="nome" required maxlength="255"
                               placeholder="Es. Script sincronizzazione">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Permessi (abilities) <span class="text-danger">*</span></label>
                        @foreach ($permessiDisponibili as $permesso)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="abilities[]"
                                       value="{{ $permesso }}" id="ability-{{ $loop->index }}">
                                <label class="form-check-label" for="ability-{{ $loop->index }}"><code>{{ $permesso }}</code></label>
                            </div>
                        @endforeach
                    </div>
                    <div class="mb-3">
                        <label for="apiTokenScadenza" class="form-label">Scadenza (opzionale)</label>
                        <input type="date" class="form-control" id="apiTokenScadenza" name="scadenza">
                    </div>
                    <div class="form-text">
                        Il token è legato al tuo utente: stesso ambito (associazione) e revoca immediata.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-person-badge me-1"></i>Crea Token</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Warning chiave globale
    const globaleSwitch = document.getElementById('apiKeyGlobale');
    if (globaleSwitch) {
        globaleSwitch.addEventListener('change', function () {
            document.getElementById('apiKeyGlobaleWarning').classList.toggle('d-none', !this.checked);
        });
    }

    // Selezionando '*' tutte le altre checkbox vengono selezionate e disabilitate
    const checks = Array.from(document.querySelectorAll('.permesso-check'));
    const wildcard = checks.find((c) => c.value === '*');
    if (wildcard) {
        wildcard.addEventListener('change', function () {
            checks.filter((c) => c !== wildcard).forEach((c) => {
                c.checked = this.checked ? true : c.checked;
                c.disabled = this.checked;
            });
        });
    }
</script>
@endpush
