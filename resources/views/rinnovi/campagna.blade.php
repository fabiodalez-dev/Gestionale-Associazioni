@extends('layouts.app')

@section('title', 'Campagna rinnovi '.$anno.' - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-1">Campagna rinnovi {{ $anno }}</h1>
            <p class="text-muted mb-0">Soci attivi senza tessera per il {{ $anno }}: invia il link di pagamento personale per il rinnovo.</p>
        </div>
        <a href="{{ route('tessere.index') }}" class="btn btn-outline-secondary"><i class="bi bi-card-heading me-1"></i>Tessere</a>
    </div>

    {{-- Contatori --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Da rinnovare</div>
                    <div class="fs-3 fw-semibold" data-testid="contatore-da-rinnovare">{{ $contatori['da_rinnovare'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Email inviate</div>
                    <div class="fs-3 fw-semibold text-primary" data-testid="contatore-inviate">{{ $contatori['inviate'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Quote pagate</div>
                    <div class="fs-3 fw-semibold text-success" data-testid="contatore-pagate">{{ $contatori['pagate'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Tessere {{ $anno }} emesse</div>
                    <div class="fs-3 fw-semibold text-success" data-testid="contatore-rinnovate">{{ $contatori['rinnovate'] }}</div>
                </div>
            </div>
        </div>
    </div>

    @if ($soci->isEmpty())
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-check2-circle display-5 d-block mb-2 opacity-50"></i>
                Tutti i soci attivi hanno già la tessera {{ $anno }}. Nessun rinnovo da sollecitare.
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('campagna-rinnovi.invia') }}" id="campagnaForm">
            @csrf
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="selezionaTutti">
                        <label class="form-check-label" for="selezionaTutti">Seleziona tutti</label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Invia email di rinnovo ai selezionati
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 36px;"></th>
                                <th>Socio</th>
                                <th>Email</th>
                                <th>Tipo socio</th>
                                <th>Quota {{ $anno }}</th>
                                <th>Stato campagna</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($soci as $socio)
                                @php
                                    $quota = $socio->getAttribute('quota_anno');
                                    $inviata = (bool) $socio->getAttribute('campagna_inviata');
                                    $pagata = (bool) $socio->getAttribute('campagna_pagata');
                                    $contattabile = filled($socio->email) && ! $socio->email_opt_out;
                                @endphp
                                <tr>
                                    <td>
                                        <input class="form-check-input socio-check" type="checkbox" name="socio_ids[]"
                                               value="{{ $socio->id }}" @disabled(! $contattabile)>
                                    </td>
                                    <td>
                                        <a href="{{ route('soci.show', $socio) }}">{{ $socio->cognome }} {{ $socio->nome }}</a>
                                        <div class="text-muted small">n. {{ $socio->numero_socio }}</div>
                                    </td>
                                    <td>
                                        @if (blank($socio->email))
                                            <span class="text-muted">—</span>
                                        @elseif ($socio->email_opt_out)
                                            {{ $socio->email }} <span class="badge text-bg-secondary">Opt-out</span>
                                        @else
                                            {{ $socio->email }}
                                        @endif
                                    </td>
                                    <td>{{ $socio->tipoSocio?->nome ?? '—' }}</td>
                                    <td>
                                        @if ($quota !== null)
                                            &euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}
                                            @if ($quota->isPagata())
                                                <span class="badge text-bg-success">Pagata</span>
                                            @else
                                                <span class="badge text-bg-secondary">Da pagare</span>
                                            @endif
                                        @else
                                            <span class="text-muted">Da creare</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($pagata)
                                            <span class="badge text-bg-success">Pagata</span>
                                        @elseif ($inviata)
                                            <span class="badge text-bg-primary">Inviata</span>
                                        @else
                                            <span class="badge text-bg-light text-dark border">Da inviare</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        @push('scripts')
            <script>
                document.getElementById('selezionaTutti')?.addEventListener('change', function () {
                    document.querySelectorAll('.socio-check:not(:disabled)')
                        .forEach((cb) => { cb.checked = this.checked; });
                });
            </script>
        @endpush
    @endif
@endsection
