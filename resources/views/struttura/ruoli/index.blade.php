@extends('layouts.app')

@section('title', 'Ruoli e permessi - '.session('app_name', config('app.name')))

@section('content')
<div class="d-flex justify-content-between align-items-end pb-3 mb-4 border-bottom">
    <div>
        <h1 class="h2 mb-1"><i class="bi bi-shield-check me-2"></i>Ruoli e permessi</h1>
        <p class="text-muted mb-0">Assegna le cariche allo staff dell'associazione: i permessi dei ruoli assegnati si sommano.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-people me-1"></i>Staff dell'associazione</h5></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Utente</th>
                    <th>Ruoli</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($staff as $utente)
                    @php($assegnati = $utente->roles->pluck('name')->all())
                    <tr>
                        <td>
                            <strong>{{ $utente->nome_completo }}</strong>
                            <div class="small text-muted">{{ $utente->email }}</div>
                            @unless ($utente->attivo)<span class="badge bg-secondary-subtle text-secondary-emphasis">Disattivato</span>@endunless
                        </td>
                        <td>
                            @if ($utente->isAdminAssociazione())
                                <span class="badge bg-primary-subtle text-primary-emphasis">Amministratore</span>
                                <span class="small text-muted ms-1">tutti i permessi</span>
                            @else
                                @forelse (array_intersect(array_keys($ruoliStaff), $assegnati) as $ruolo)
                                    <span class="badge bg-body-secondary text-body me-1">{{ $ruoliStaff[$ruolo]['label'] }}</span>
                                @empty
                                    <span class="small text-muted">Nessun ruolo: al prossimo accesso riceverà "Operatore"</span>
                                @endforelse
                            @endif
                        </td>
                        <td class="text-end">
                            @can('assegnaRuoli', $utente)
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#ruoli-{{ $utente->id }}" aria-expanded="false" aria-controls="ruoli-{{ $utente->id }}">
                                    <i class="bi bi-pencil me-1"></i>Modifica ruoli
                                </button>
                            @endcan
                        </td>
                    </tr>
                    @can('assegnaRuoli', $utente)
                        <tr class="collapse" id="ruoli-{{ $utente->id }}">
                            <td colspan="3" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('ruoli.update', $utente) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="row g-2">
                                        @foreach ($ruoliStaff as $ruolo => $info)
                                            <div class="col-md-6 col-xl-4">
                                                <div class="form-check border rounded p-2 ps-4 h-100 bg-body">
                                                    <input class="form-check-input" type="checkbox" name="ruoli[]" value="{{ $ruolo }}" id="ruolo-{{ $utente->id }}-{{ $ruolo }}" @checked(in_array($ruolo, $assegnati, true))>
                                                    <label class="form-check-label" for="ruolo-{{ $utente->id }}-{{ $ruolo }}">
                                                        <span class="fw-medium">{{ $info['label'] }}</span>
                                                        <span class="d-block small text-muted">{{ $info['descrizione'] }}</span>
                                                    </label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm mt-3"><i class="bi bi-check-lg me-1"></i>Salva ruoli</button>
                                </form>
                            </td>
                        </tr>
                    @endcan
                @empty
                    <tr><td colspan="3" class="text-center text-muted py-4">Nessun utente staff in questa associazione.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent small text-muted">
        Nuovi utenti e cambi tra amministratore e operatore si gestiscono dalla pagina Utenti del super admin.
    </div>
</div>

<div class="card">
    <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-grid-3x3 me-1"></i>Cosa può fare ogni ruolo</h5></div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0 small text-center">
            <thead>
                <tr>
                    <th class="text-start">Area</th>
                    @foreach ($ruoliMatrice as $ruolo)
                        <th>{{ \App\Support\RuoliAssociazione::label($ruolo) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($moduli as $modulo => $etichetta)
                    <tr>
                        <th class="text-start fw-normal">{{ $etichetta }}</th>
                        @foreach ($ruoliMatrice as $ruolo)
                            @php($azioniRuolo = collect($azioni)->filter(fn ($l, $a) => in_array("{$modulo}.{$a}", $permessiPerRuolo[$ruolo], true)))
                            <td>
                                @if ($azioniRuolo->isEmpty())
                                    <span class="text-body-tertiary" aria-label="Nessun accesso">—</span>
                                @elseif ($azioniRuolo->count() === count($azioni))
                                    <span class="badge bg-success-subtle text-success-emphasis">completo</span>
                                @elseif ($azioniRuolo->keys()->all() === ['view'])
                                    <span class="badge bg-info-subtle text-info-emphasis">lettura</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning-emphasis font-monospace" title="{{ $azioniRuolo->join(', ') }}">{{ $azioniRuolo->map(fn ($l) => mb_substr($l, 0, 1))->join(' ') }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent small text-muted">
        <span class="badge bg-success-subtle text-success-emphasis">completo</span> tutte le azioni ·
        <span class="badge bg-info-subtle text-info-emphasis">lettura</span> solo consultazione ·
        <span class="badge bg-warning-subtle text-warning-emphasis font-monospace">V C M</span> Vedere, Creare, Modificare, Eliminare ·
        — nessun accesso
    </div>
</div>
@endsection
