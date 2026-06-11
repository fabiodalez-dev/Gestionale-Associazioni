@extends('layouts.app')

@section('title', $account->cognome.' '.$account->nome.' - Account frontend - '.session('app_name', config('app.name')))

@php
    use App\Models\Prenotazione;

    $badgeStato = [
        Prenotazione::STATO_CONFERMATA => ['bg-success', 'Confermata'],
        Prenotazione::STATO_IN_ATTESA_PAGAMENTO => ['bg-warning text-dark', 'In attesa di pagamento'],
        Prenotazione::STATO_LISTA_ATTESA => ['bg-info text-dark', "Lista d'attesa"],
        Prenotazione::STATO_ANNULLATA => ['bg-secondary', 'Annullata'],
        Prenotazione::STATO_SCADUTA => ['bg-dark', 'Scaduta'],
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2">
                <i class="bi bi-person-vcard me-2"></i>{{ $account->cognome }} {{ $account->nome }}
                @if ($account->attivo)
                    <span class="badge bg-success fs-6 align-middle">Attivo</span>
                @else
                    <span class="badge bg-danger fs-6 align-middle">Disattivato</span>
                @endif
            </h1>
            <p class="text-muted mb-0">Account del frontend pubblico</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('accounts.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Account
            </a>
            @if ($account->attivo)
                <form method="POST" action="{{ route('accounts.disattiva', $account) }}" class="m-0"
                      onsubmit="return confirm('Disattivare questo account? Non potrà più accedere né prenotare.');">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger">
                        <i class="bi bi-person-x me-1"></i>Disattiva
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('accounts.riattiva', $account) }}" class="m-0">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-success">
                        <i class="bi bi-person-check me-1"></i>Riattiva
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3 mb-4">
        {{-- Anagrafica --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-person me-2"></i>Anagrafica</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8">
                            {{ $account->email }}
                            @if ($account->email_verified_at)
                                <span class="badge bg-success ms-1">Verificata</span>
                            @else
                                <span class="badge bg-secondary ms-1">Non verificata</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">Telefono</dt>
                        <dd class="col-sm-8">{{ $account->telefono ?: '-' }}</dd>
                        <dt class="col-sm-4">Registrato il</dt>
                        <dd class="col-sm-8">{{ $account->created_at?->format('d/m/Y H:i') }}</dd>
                        <dt class="col-sm-4">Ultimo accesso</dt>
                        <dd class="col-sm-8">{{ $account->ultimo_accesso_at?->format('d/m/Y H:i') ?: '-' }}</dd>
                        <dt class="col-sm-4">Privacy accettata</dt>
                        <dd class="col-sm-8">{{ $account->privacy_accettata_at?->format('d/m/Y H:i') ?: '-' }}</dd>
                        <dt class="col-sm-4">Richiesta socio</dt>
                        <dd class="col-sm-8">
                            @if ($account->richiesta_socio_at)
                                {{ $account->richiesta_socio_at->format('d/m/Y H:i') }}
                                @if (! $account->socio_id)
                                    <span class="badge bg-warning text-dark ms-1">Pendente</span>
                                @endif
                            @else
                                -
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Collegamento socio --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-link-45deg me-2"></i>Collegamento socio</div>
                <div class="card-body">
                    @if ($account->socio)
                        <p class="mb-2">
                            <span class="badge bg-success me-1">Collegato</span>
                            Socio n. <strong>{{ $account->socio->numero_socio }}</strong> —
                            {{ $account->socio->cognome }} {{ $account->socio->nome }}
                            <span class="badge bg-light text-dark border ms-1">{{ $account->socio->stato }}</span>
                        </p>
                        <p class="text-muted small mb-0">
                            L'account vede tessera e quote del socio nella sua area riservata.
                        </p>
                    @elseif ($account->socio_id)
                        <p class="text-muted mb-0">Collegato a un socio non più disponibile.</p>
                    @else
                        @if ($socioSuggerito)
                            <div class="alert alert-info py-2 small">
                                <i class="bi bi-lightbulb me-1"></i>
                                Match suggerito per email: <strong>{{ $socioSuggerito->cognome }} {{ $socioSuggerito->nome }}</strong>
                                (n. {{ $socioSuggerito->numero_socio }}, {{ $socioSuggerito->email }}).
                                Il collegamento resta un'azione esplicita: verifica l'identità prima di confermare.
                            </div>
                        @endif

                        <form method="POST" action="{{ route('accounts.collega-socio', $account) }}" class="mb-3">
                            @csrf
                            <label for="socio_id" class="form-label">Collega a un socio esistente</label>
                            <div class="input-group">
                                <select class="form-select @error('socio_id') is-invalid @enderror" id="socio_id" name="socio_id" required>
                                    <option value="">-- Seleziona socio --</option>
                                    @foreach ($sociCollegabili as $socio)
                                        <option value="{{ $socio->id }}" @selected(old('socio_id', $socioSuggerito?->id) === $socio->id)>
                                            {{ $socio->cognome }} {{ $socio->nome }} (n. {{ $socio->numero_socio }}){{ filled($socio->email) ? ' - '.$socio->email : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary"
                                        onclick="return confirm('Collegare questo account al socio selezionato?');">
                                    <i class="bi bi-link-45deg me-1"></i>Collega
                                </button>
                            </div>
                            @error('socio_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </form>

                        <hr>
                        <form method="POST" action="{{ route('accounts.promuovi-socio', $account) }}" class="m-0"
                              onsubmit="return confirm('Creare un nuovo socio (stato Attivo) dall\'anagrafica di questo account e collegarlo?');">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="bi bi-person-plus me-1"></i>Promuovi a nuovo socio
                            </button>
                            <div class="form-text">
                                Crea il socio dall'anagrafica dell'account (numero socio progressivo) e lo collega.
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Prenotazioni dell'account --}}
    <div class="card">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-ticket-perforated me-2"></i>Prenotazioni</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Codice</th>
                            <th>Evento</th>
                            <th class="text-center">Posti</th>
                            <th>Stato</th>
                            <th>Importo</th>
                            <th>Check-in</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($prenotazioni as $prenotazione)
                            @php
                                [$badgeClass, $badgeLabel] = $badgeStato[$prenotazione->stato] ?? ['bg-secondary', ucfirst($prenotazione->stato)];
                            @endphp
                            <tr>
                                <td class="fw-semibold">{{ $prenotazione->codice }}</td>
                                <td>
                                    @if ($prenotazione->evento)
                                        <a href="{{ route('eventi.prenotazioni.index', $prenotazione->evento_id) }}">{{ $prenotazione->evento->titolo }}</a>
                                        <div class="text-muted small">{{ $prenotazione->evento->data_evento?->format('d/m/Y H:i') }}</div>
                                    @else
                                        <span class="text-muted">Evento eliminato</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $prenotazione->posti }}</td>
                                <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                                <td>
                                    @if ((float) $prenotazione->importo_totale > 0)
                                        € {{ number_format((float) $prenotazione->importo_totale, 2, ',', '.') }}
                                        @if ($prenotazione->pagata_at)
                                            <span class="text-success small"><i class="bi bi-check-circle"></i></span>
                                        @endif
                                    @else
                                        <span class="text-muted">Gratuita</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($prenotazione->checkin_at)
                                        <span class="text-success">{{ $prenotazione->checkin_at->format('d/m/Y H:i') }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Nessuna prenotazione.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($prenotazioni->hasPages())
            <div class="card-footer bg-white">
                {{ $prenotazioni->links() }}
            </div>
        @endif
    </div>
@endsection
