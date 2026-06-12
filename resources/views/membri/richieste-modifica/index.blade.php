@extends('layouts.app')

@section('title', 'Richieste modifiche - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-person-lines-fill me-2"></i>Richieste modifica dati</h1>
            <p class="text-muted mb-0">Modifiche anagrafiche proposte dai soci dall'area riservata: controlla il diff e approva o rifiuta.</p>
        </div>
    </div>

    {{-- Richieste in attesa con diff campo per campo --}}
    <h5 class="mb-3">In attesa <span class="badge bg-warning text-dark">{{ $pendenti->count() }}</span></h5>

    @if ($pendenti->isEmpty())
        <div class="alert alert-light border text-center">
            <i class="bi bi-check2-circle me-1"></i>Nessuna richiesta di modifica in attesa.
        </div>
    @else
        @foreach ($pendenti as $richiesta)
            @php
                $socio = $richiesta->socio;
                $proposte = $service->proposteVisibili($richiesta);
                $origineLabel = $richiesta->origine instanceof \App\Models\Account
                    ? 'Account collegato ('.$richiesta->origine->email.')'
                    : 'Area soci';
            @endphp
            <div class="card mb-3">
                <div class="card-header bg-white d-flex justify-content-between flex-wrap align-items-center gap-2">
                    <div>
                        @if ($socio)
                            <a href="{{ route('soci.show', $socio) }}" class="fw-semibold text-decoration-none">{{ $socio->nome_completo }}</a>
                            <span class="text-muted small">— Socio n. {{ $socio->numero_socio }}</span>
                        @else
                            <span class="text-muted">Socio eliminato</span>
                        @endif
                    </div>
                    <div class="text-muted small">
                        <i class="bi bi-clock me-1"></i>{{ $richiesta->created_at?->format('d/m/Y H:i') }}
                        &middot; <i class="bi bi-box-arrow-in-right me-1"></i>{{ $origineLabel }}
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead>
                                <tr><th>Campo</th><th>Valore attuale</th><th>Valore proposto</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($proposte as $campo => $valore)
                                    <tr>
                                        <td class="fw-semibold">{{ \App\Services\Membri\ModificaDatiService::LABELS[$campo] ?? $campo }}</td>
                                        <td class="text-muted">{{ $socio ? ($service->valoreCorrente($socio, $campo) ?: '—') : '—' }}</td>
                                        <td><span class="badge bg-primary-subtle text-primary-emphasis border">{{ $valore }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <form method="POST" action="{{ route('richieste-modifica-dati.approva', $richiesta) }}"
                              onsubmit="return confirm('Applicare le modifiche proposte alla scheda del socio?');">
                            @csrf
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg me-1"></i>Approva e applica
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse"
                                data-bs-target="#rifiuta-{{ $richiesta->id }}" aria-expanded="false">
                            <i class="bi bi-x-lg me-1"></i>Rifiuta…
                        </button>
                    </div>

                    <div class="collapse mt-3" id="rifiuta-{{ $richiesta->id }}">
                        <form method="POST" action="{{ route('richieste-modifica-dati.rifiuta', $richiesta) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-8">
                                <label class="form-label" for="motivo-{{ $richiesta->id }}">Motivo del rifiuto * <span class="text-muted small">(visibile al socio)</span></label>
                                <input type="text" class="form-control @error('motivo') is-invalid @enderror" id="motivo-{{ $richiesta->id }}"
                                       name="motivo" maxlength="500" required value="{{ old('motivo') }}">
                                @error('motivo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Conferma rifiuto</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif

    {{-- Storico richieste gestite --}}
    <h5 class="mt-4 mb-3">Storico</h5>

    @if ($storico->isEmpty())
        <p class="text-muted">Nessuna richiesta gestita finora.</p>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Socio</th>
                        <th>Modifiche proposte</th>
                        <th>Esito</th>
                        <th>Gestita da</th>
                        <th>Gestita il</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($storico as $richiesta)
                        @php
                            $proposte = $service->proposteVisibili($richiesta);
                            $motivo = $service->motivoRifiuto($richiesta);
                        @endphp
                        <tr>
                            <td>
                                @if ($richiesta->socio)
                                    <a href="{{ route('soci.show', $richiesta->socio) }}" class="text-decoration-none">{{ $richiesta->socio->nome_completo }}</a>
                                @else
                                    <span class="text-muted">Socio eliminato</span>
                                @endif
                            </td>
                            <td class="small">
                                @foreach ($proposte as $campo => $valore)
                                    <div>{{ \App\Services\Membri\ModificaDatiService::LABELS[$campo] ?? $campo }}: <strong>{{ $valore }}</strong></div>
                                @endforeach
                            </td>
                            <td>
                                @if ($richiesta->stato === \App\Models\RichiestaModificaDati::STATO_APPROVATA)
                                    <span class="badge bg-success">Approvata</span>
                                @else
                                    <span class="badge bg-danger">Rifiutata</span>
                                    @if ($motivo)
                                        <div class="small text-muted mt-1">{{ $motivo }}</div>
                                    @endif
                                @endif
                            </td>
                            <td class="small">{{ $richiesta->gestitaDa?->email ?? '—' }}</td>
                            <td class="small">{{ $richiesta->gestita_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-center mt-3">
            {{ $storico->links() }}
        </div>
    @endif
@endsection
