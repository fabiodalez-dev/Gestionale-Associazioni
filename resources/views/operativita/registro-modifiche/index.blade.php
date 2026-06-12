@extends('layouts.app')

@section('title', 'Registro modifiche - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-clock-history me-2"></i>Registro modifiche</h1>
            <p class="text-muted mb-0">
                <span class="badge bg-primary">{{ $attivita->total() }}</span>
                {{ $attivita->total() === 1 ? 'modifica registrata' : 'modifiche registrate' }}
            </p>
        </div>
    </div>

    {{-- Filtri (f.6: modello / utente / periodo / azione) --}}
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('registro-modifiche.index') }}" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="filtro_modello">Modello</label>
                    <select class="form-select form-select-sm" id="filtro_modello" name="modello">
                        <option value="">Tutti</option>
                        @foreach ($modelli as $etichetta => $classe)
                            <option value="{{ $etichetta }}" @selected($filtri['modello'] === $etichetta)>{{ $etichetta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="filtro_utente">Utente</label>
                    <select class="form-select form-select-sm" id="filtro_utente" name="utente_id">
                        <option value="">Tutti</option>
                        @foreach ($utentiTenant as $u)
                            <option value="{{ $u->id }}" @selected($filtri['utente_id'] === $u->id)>{{ $u->nome_completo ?: $u->email }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="filtro_azione">Azione</label>
                    <select class="form-select form-select-sm" id="filtro_azione" name="azione">
                        <option value="">Tutte</option>
                        @foreach ($azioni as $az)
                            <option value="{{ $az }}" @selected($filtri['azione'] === $az)>
                                {{ ['created' => 'Creazione', 'updated' => 'Modifica', 'deleted' => 'Eliminazione', 'restored' => 'Ripristino'][$az] ?? $az }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="filtro_dal">Dal</label>
                    <input type="date" class="form-control form-control-sm" id="filtro_dal" name="dal" value="{{ $filtri['dal'] }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="filtro_al">Al</label>
                    <input type="date" class="form-control form-control-sm" id="filtro_al" name="al" value="{{ $filtri['al'] }}">
                </div>
                <div class="col-3 col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel"></i></button>
                </div>
                <div class="col-3 col-md-12 col-lg-auto">
                    <a href="{{ route('registro-modifiche.index') }}" class="btn btn-sm btn-outline-secondary w-100">Azzera</a>
                </div>
            </form>
        </div>
    </div>

    @if ($attivita->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-search me-1"></i>Nessuna modifica registrata con i filtri correnti.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Utente</th>
                        <th>Azione</th>
                        <th>Elemento</th>
                        <th>Dettaglio modifiche</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($attivita as $log)
                        @php
                            $badgeAzione = match ($log->event) {
                                'created' => 'success',
                                'updated' => 'primary',
                                'deleted' => 'danger',
                                'restored' => 'info',
                                default => 'secondary',
                            };
                            // Questa versione di activitylog salva il diff in attribute_changes
                            $cambi = $log->attribute_changes ?? $log->properties ?? collect();
                            $nuovi = (array) ($cambi['attributes'] ?? []);
                            $vecchi = (array) ($cambi['old'] ?? []);
                            $campi = array_unique([...array_keys($nuovi), ...array_keys($vecchi)]);
                            $formatta = fn ($v) => match (true) {
                                $v === null, $v === '' => '—',
                                is_bool($v) => $v ? 'Sì' : 'No',
                                is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
                                default => \Illuminate\Support\Str::limit((string) $v, 80),
                            };
                        @endphp
                        <tr>
                            <td class="text-nowrap small">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                            <td class="small">
                                {{ $log->causer?->nome_completo ?? $log->causer?->email ?? 'Sistema' }}
                            </td>
                            <td>
                                <span class="badge bg-{{ $badgeAzione }}">
                                    {{ ['created' => 'Creazione', 'updated' => 'Modifica', 'deleted' => 'Eliminazione', 'restored' => 'Ripristino'][$log->event] ?? ($log->event ?: $log->description) }}
                                </span>
                            </td>
                            <td class="small">
                                <span class="fw-semibold">{{ $etichetteModelli[$log->subject_type] ?? class_basename((string) $log->subject_type) }}</span>
                                <div class="text-muted text-truncate" style="max-width: 180px" title="{{ $log->subject_id }}">{{ $log->subject_id }}</div>
                            </td>
                            <td class="small">
                                @if ($campi === [])
                                    <span class="text-muted">—</span>
                                @else
                                    <ul class="list-unstyled mb-0">
                                        @foreach ($campi as $campo)
                                            <li>
                                                <span class="text-muted">{{ $campo }}:</span>
                                                @if ($log->event === 'updated')
                                                    <del class="text-danger">{{ $formatta($vecchi[$campo] ?? null) }}</del>
                                                    <i class="bi bi-arrow-right mx-1"></i>
                                                @endif
                                                <span class="text-success">{{ $formatta($nuovi[$campo] ?? null) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $attivita->links() }}
        </div>
    @endif
@endsection
