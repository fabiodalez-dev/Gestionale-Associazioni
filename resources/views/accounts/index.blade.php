@extends('layouts.app')

@section('title', 'Account frontend - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-person-vcard me-2"></i>Account frontend</h1>
            <p class="text-muted mb-0">Utenti registrati sul sito pubblico di {{ session('associazione_nome', 'Manager') }}</p>
        </div>
        @if ($richiestePendenti > 0)
            <a href="{{ route('accounts.index', ['richieste' => 1]) }}" class="btn btn-warning">
                <i class="bi bi-person-plus me-1"></i>Richieste socio pendenti
                <span class="badge bg-dark ms-1">{{ $richiestePendenti }}</span>
            </a>
        @endif
    </div>

    <form method="GET" action="{{ route('accounts.index') }}" class="row g-2 mb-3">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="{{ $search }}" class="form-control"
                       placeholder="Cerca per nome, cognome o email...">
                @if ($soloRichieste)
                    <input type="hidden" name="richieste" value="1">
                @endif
                <button type="submit" class="btn btn-outline-primary">Cerca</button>
                @if ($search !== '' || $soloRichieste)
                    <a href="{{ route('accounts.index') }}" class="btn btn-outline-secondary" title="Azzera filtri">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Account</th>
                            <th>Email</th>
                            <th class="text-center">Verifica</th>
                            <th class="text-center">Prenotazioni</th>
                            <th>Socio</th>
                            <th class="text-center">Stato</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($accounts as $account)
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $account->cognome }} {{ $account->nome }}</span>
                                    @if ($account->richiesta_socio_at && ! $account->socio_id)
                                        <span class="badge bg-warning text-dark ms-1" title="Richiesta del {{ $account->richiesta_socio_at->format('d/m/Y') }}">
                                            <i class="bi bi-person-plus me-1"></i>Richiesta socio
                                        </span>
                                    @endif
                                    <div class="text-muted small">Registrato {{ $account->created_at?->format('d/m/Y') }}</div>
                                </td>
                                <td>{{ $account->email }}</td>
                                <td class="text-center">
                                    @if ($account->email_verified_at)
                                        <span class="badge bg-success" title="Verificata il {{ $account->email_verified_at->format('d/m/Y H:i') }}">Verificata</span>
                                    @else
                                        <span class="badge bg-secondary">Non verificata</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($account->prenotazioni_count > 0)
                                        <span class="badge bg-primary">{{ $account->prenotazioni_count }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($account->socio_id)
                                        <span class="badge bg-success"><i class="bi bi-link-45deg me-1"></i>Collegato</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($account->attivo)
                                        <span class="badge bg-success">Attivo</span>
                                    @else
                                        <span class="badge bg-danger">Disattivato</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('accounts.show', $account) }}" class="btn btn-outline-primary btn-sm" title="Dettaglio">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-person-vcard d-block mb-2" style="font-size: 2rem;"></i>
                                    @if ($search !== '' || $soloRichieste)
                                        Nessun account corrisponde ai filtri.
                                    @else
                                        Nessun account registrato sul frontend pubblico.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($accounts->hasPages())
            <div class="card-footer bg-white">
                {{ $accounts->links() }}
            </div>
        @endif
    </div>
@endsection
