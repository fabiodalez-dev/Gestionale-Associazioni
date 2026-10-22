@extends('layouts.app')

@section('title', 'Aggiornamenti - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-end pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-arrow-repeat me-2"></i>Aggiornamenti</h1>
            <p class="text-muted mb-0">Aggiornamento in-app della piattaforma (solo super admin): backup, download verificato, copia dei file, migration, rollback automatico.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>{{ $errors->first() }}</div>
    @endif

    @if ($manutenzioneScaduta)
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <div><i class="bi bi-cone-striped me-1"></i>Il sito è in manutenzione da più di {{ config('update.maintenance_stale_minutes') }} minuti senza un aggiornamento in corso: probabilmente un update è stato interrotto. Controlla lo storico, poi sblocca.</div>
            <form method="POST" action="{{ route('aggiornamenti.sblocca') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-warning">Togli la manutenzione</button>
            </form>
        </div>
    @endif

    @if ($inCorso)
        <div class="alert alert-info"><i class="bi bi-hourglass-split me-1"></i>Un aggiornamento è in corso in un altro processo: la pagina si aggiorna da sola al termine.</div>
    @endif

    {{-- Stato versione --}}
    <div class="card mb-4" id="cardVersione"
         data-url-stato="{{ route('aggiornamenti.stato') }}"
         data-url-controlla="{{ route('aggiornamenti.controlla') }}"
         data-in-corso="{{ $inCorso ? 1 : 0 }}">
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-auto">
                    <div class="metric-chip" style="width:48px;height:48px;font-size:1.4rem;">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
                <div class="col">
                    <div class="text-muted small text-uppercase">Versione installata</div>
                    <div class="fs-3 fw-semibold">v{{ $stato['corrente'] }}</div>
                    <div class="small text-muted">Canale: {{ $canale === 'rc' ? 'anche prerelease' : 'stabile' }} &middot; <a href="#" id="btnControlla">controlla ora</a></div>
                </div>

                <div class="col-12 col-md-auto ms-md-auto text-md-end" id="statoRelease">
                    @if ($stato['errore'])
                        <span class="badge bg-secondary"><i class="bi bi-wifi-off me-1"></i>{{ $stato['errore'] }}</span>
                    @elseif ($stato['disponibile'])
                        <span class="badge bg-success mb-2"><i class="bi bi-stars me-1"></i>Aggiornamento disponibile: v{{ $stato['ultima'] }}</span>
                        @if (! $stato['pacchetto'])
                            <div class="small text-danger">La release non ha il pacchetto installabile: aggiorna da riga di comando.</div>
                        @elseif ($selfUpdate && ! $inCorso && $requisiti['ok'])
                            <form method="POST" action="{{ route('aggiornamenti.applica') }}" id="formAggiorna"
                                  data-versione="{{ $stato['ultima'] }}">
                                @csrf
                                <input type="hidden" name="versione" value="{{ $stato['ultima'] }}">
                                <button type="submit" class="btn btn-primary" id="btnAggiorna">
                                    <i class="bi bi-download me-1"></i>Aggiorna alla v{{ $stato['ultima'] }}
                                </button>
                            </form>
                        @elseif (! $selfUpdate)
                            <div class="text-muted small">Aggiornamento dalla pagina disattivato: <code>php artisan app:aggiorna</code></div>
                        @elseif (! $requisiti['ok'])
                            <div class="text-danger small">Requisiti non soddisfatti (vedi sotto).</div>
                        @endif
                    @else
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sei alla versione più recente</span>
                    @endif
                </div>
            </div>

            {{-- Avanzamento (riempito dal JS durante l'update) --}}
            <div id="avanzamento" class="d-none mt-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <div id="avanzamentoTesto" class="small">Aggiornamento in corso: backup del database, download e verifica del pacchetto, copia dei file, migration. Non chiudere la pagina.</div>
                </div>
            </div>
            <div id="esitoUpdate" class="alert d-none mt-3 mb-0" role="status"></div>

            @if ($stato['disponibile'] && filled($stato['note']))
                <hr>
                <div class="fw-semibold mb-2">Novità della v{{ $stato['ultima'] }}</div>
                <div class="small text-muted" style="white-space: pre-wrap;">{{ \Illuminate\Support\Str::limit($stato['note'], 1500) }}</div>
                @if ($stato['url'])
                    <a href="{{ $stato['url'] }}" target="_blank" rel="noopener" class="small">Note di rilascio complete &rsaquo;</a>
                @endif
            @endif
        </div>
    </div>

    <div class="row g-4 mb-4">
        {{-- Requisiti --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clipboard-check me-1"></i>Requisiti per aggiornare</span>
                    <span class="badge {{ $requisiti['ok'] ? 'bg-success' : 'bg-danger' }}">{{ $requisiti['ok'] ? 'tutti ok' : 'da sistemare' }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th>Requisito</th><th>Richiesto</th><th>Attuale</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($requisiti['righe'] as $r)
                                <tr>
                                    <td class="small">{{ $r['nome'] }}</td>
                                    <td class="small text-muted">{{ $r['richiesto'] }}</td>
                                    <td class="small {{ $r['ok'] ? '' : 'text-danger' }}">{{ $r['attuale'] }}</td>
                                    <td><i class="bi {{ $r['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' }}"></i></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Pacchetto manuale + token --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-file-earmark-zip me-1"></i>Aggiornamento da pacchetto</div>
                <div class="card-body">
                    <p class="small text-muted">Se il server non raggiunge GitHub, scarica tu il pacchetto <code>gestionale-associazioni-vX.Y.Z.zip</code> dalla release e caricalo qui: stesso flusso (backup, copia, migration, rollback), senza verifica del digest.</p>
                    <form method="POST" action="{{ route('aggiornamenti.carica') }}" enctype="multipart/form-data" id="formCarica" class="d-flex gap-2 align-items-start">
                        @csrf
                        <input type="file" name="pacchetto" accept=".zip,application/zip" class="form-control form-control-sm" required @disabled(! $selfUpdate || $inCorso)>
                        <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap" id="btnCarica" @disabled(! $selfUpdate || $inCorso)>Carica e aggiorna</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0">Da terminale, come proprietario dei file: <code>php artisan app:aggiorna --pacchetto=/percorso/file.zip</code> oppure <code>--versione=X.Y.Z</code>.</p>

                    <hr>
                    <div class="fw-semibold small mb-1">Token GitHub <span class="text-muted fw-normal">(facoltativo: alza il limite di richieste all'API)</span></div>
                    <form method="POST" action="{{ route('aggiornamenti.token') }}" class="d-flex gap-2">
                        @csrf
                        <input type="password" name="token" class="form-control form-control-sm" autocomplete="off" placeholder="{{ $tokenPresente ? 'Token presente (lascia vuoto per rimuoverlo)' : 'ghp_…' }}">
                        <button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap">Salva</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- Backup --}}
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-safe me-1"></i>Backup del database</div>
                <ul class="list-group list-group-flush">
                    @forelse ($backup as $b)
                        <li class="list-group-item d-flex justify-content-between align-items-center small">
                            <div>
                                <div class="text-truncate" style="max-width: 260px;">{{ $b['nome'] }}</div>
                                <div class="text-muted">{{ date('d/m/Y H:i', $b['data']) }} &middot; {{ number_format($b['byte'] / 1024, 0, ',', '.') }} KB</div>
                            </div>
                            <a href="{{ route('aggiornamenti.backup', $b['nome']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
                        </li>
                    @empty
                        <li class="list-group-item text-muted small">Nessun backup presente. L'updater ne crea uno prima di ogni aggiornamento; <code>php artisan backup:run</code> a mano.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Storico --}}
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Storico aggiornamenti</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr><th>Data</th><th>Da → A</th><th>Esito</th><th>Eseguito da</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($storico as $log)
                                <tr>
                                    <td class="small text-nowrap">{{ $log->avviato_at?->format('d/m/Y H:i') }}</td>
                                    <td class="small">v{{ $log->from_version }} &rarr; v{{ $log->to_version }}</td>
                                    <td>
                                        @php
                                            $badge = match ($log->stato) {
                                                'completato' => 'bg-success',
                                                'fallito' => 'bg-danger',
                                                'rollback' => 'bg-warning text-dark',
                                                default => 'bg-secondary',
                                            };
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst($log->stato) }}</span>
                                        @if ($log->messaggio_errore)
                                            <div class="small text-muted mt-1" style="max-width: 420px;">{{ \Illuminate\Support\Str::limit($log->messaggio_errore, 240) }}</div>
                                        @endif
                                    </td>
                                    <td class="small">{{ $log->esecutore?->nome_completo ?? 'CLI' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">Nessun aggiornamento registrato.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/aggiornamenti.js') }}"></script>
@endpush
