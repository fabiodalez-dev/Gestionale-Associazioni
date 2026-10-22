@extends('layouts.app')

@section('title', 'Richieste iscrizione - '.session('app_name', config('app.name')))

@php
    use App\Models\RichiestaIscrizione;
    use App\Services\Iscrizioni\FormIscrizioneService;

    /**
     * Render leggibile dello snapshot dati_form: etichette italiane per i
     * campi standard, nome reale per i campi personalizzati (custom_field).
     */
    $righeDatiForm = function (?array $datiForm) use ($nomiCampi): array {
        $righe = [];

        foreach ($datiForm ?? [] as $campo => $valore) {
            if ($campo === 'custom_field') {
                foreach (is_array($valore) ? $valore : [] as $campoId => $valoreCustom) {
                    if (is_scalar($valoreCustom) && trim((string) $valoreCustom) !== '') {
                        $righe[$nomiCampi[$campoId] ?? 'Campo personalizzato'] = (string) $valoreCustom;
                    }
                }

                continue;
            }

            if (is_scalar($valore) && trim((string) $valore) !== '') {
                $righe[FormIscrizioneService::LABELS[$campo] ?? ucfirst(str_replace('_', ' ', $campo))] = (string) $valore;
            }
        }

        return $righe;
    };
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-person-plus me-2"></i>Richieste iscrizione</h1>
            <p class="text-muted mb-0">Richieste inviate dal modulo di iscrizione del sito pubblico.</p>
        </div>
        <a href="{{ Route::has('soci.index') ? route('soci.index') : '#' }}" class="btn btn-outline-secondary">
            <i class="bi bi-people me-1"></i>Vai ai soci
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}
        </div>
    @endif

    {{-- ── Richieste in attesa ─────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-hourglass-split me-1"></i>In attesa di approvazione</span>
            <span class="badge {{ $pendenti->isEmpty() ? 'bg-secondary' : 'bg-warning text-dark' }}">{{ $pendenti->count() }}</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Persona</th>
                            <th>Dati inviati</th>
                            <th>Socio preiscritto</th>
                            <th>Ricevuta il</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pendenti as $richiesta)
                            @php
                                $righe = $righeDatiForm($richiesta->dati_form);
                                $socio = $richiesta->socio;
                                $persona = $richiesta->account ?? $socio;
                                // Anagrafica dal socio preiscritto (fonte più completa), poi dallo snapshot del form
                                $scheda = array_filter([
                                    'Telefono' => $socio?->telefono ?? $righe['Telefono'] ?? null,
                                    'Data di nascita' => $socio?->data_nascita?->format('d/m/Y') ?? $righe['Data di nascita'] ?? null,
                                    'Codice fiscale' => $socio?->codice_fiscale ?? $righe['Codice fiscale'] ?? null,
                                    'Indirizzo' => trim(implode(' ', array_filter([$socio?->indirizzo ?? $righe['Indirizzo'] ?? null, $socio?->cap ?? null, $socio?->citta ?? $righe['Citta'] ?? $righe['Città'] ?? null, $socio?->provincia ? '('.$socio->provincia.')' : null]))) ?: null,
                                ], fn ($v) => filled($v));
                                $altriCampi = array_diff_key($righe, ['Telefono' => 1, 'Data di nascita' => 1, 'Codice fiscale' => 1, 'Indirizzo' => 1, 'Citta' => 1, 'Città' => 1]);
                            @endphp
                            <tr>
                                <td>
                                    @if ($persona)
                                        <span class="fw-semibold">{{ $persona->cognome }} {{ $persona->nome }}</span>
                                        <div class="text-muted small">{{ $persona->email }}</div>
                                    @else
                                        <span class="text-muted fst-italic">Account eliminato</span>
                                    @endif
                                    @if ($richiesta->referral_codice)
                                        <div class="small text-muted"><i class="bi bi-people me-1"></i>Invitato da {{ $richiesta->socioReferente?->nome_completo ?? $richiesta->referral_codice }}</div>
                                    @endif
                                </td>
                                <td>
                                    {{-- I dati della persona si vedono subito: sono quelli su cui si decide --}}
                                    @if ($scheda === [] && $altriCampi === [])
                                        <span class="text-muted">Solo nome, cognome ed email</span>
                                    @else
                                        <dl class="row mb-0 small" style="min-width: 260px;">
                                            @foreach ($scheda + $altriCampi as $etichetta => $valore)
                                                <dt class="col-5 fw-normal text-muted">{{ $etichetta }}</dt>
                                                <dd class="col-7 mb-0">{{ $valore }}</dd>
                                            @endforeach
                                        </dl>
                                    @endif
                                </td>
                                <td>
                                    @if ($socio)
                                        <a href="{{ Route::has('soci.show') ? route('soci.show', $socio) : '#' }}">
                                            N. {{ $socio->numero_socio }}
                                        </a>
                                        <div class="text-muted small">{{ $socio->stato }}</div>
                                        <a href="{{ Route::has('soci.show') ? route('soci.show', $socio) : '#' }}" class="small">Scheda completa &rsaquo;</a>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>{{ $richiesta->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-success btn-approva-richiesta"
                                                data-bs-toggle="modal" data-bs-target="#modalApprova"
                                                data-action="{{ route('richieste-iscrizione.approva', $richiesta) }}"
                                                data-nome="{{ $persona ? $persona->cognome.' '.$persona->nome : 'richiedente' }}">
                                            <i class="bi bi-check-lg me-1"></i>Approva
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-rifiuta-richiesta"
                                                data-bs-toggle="modal" data-bs-target="#modalRifiuta"
                                                data-action="{{ route('richieste-iscrizione.rifiuta', $richiesta) }}"
                                                data-nome="{{ $persona ? $persona->cognome.' '.$persona->nome : 'richiedente' }}">
                                            <i class="bi bi-x-lg me-1"></i>Rifiuta
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox d-block mb-2" style="font-size: 2rem;"></i>
                                    Nessuna richiesta in attesa.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Storico ────────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Storico richieste gestite</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Richiedente</th>
                            <th>Esito</th>
                            <th>Gestita da</th>
                            <th>Gestita il</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($storico as $richiesta)
                            <tr>
                                <td>
                                    @if ($richiesta->account)
                                        {{ $richiesta->account->cognome }} {{ $richiesta->account->nome }}
                                        <div class="text-muted small">{{ $richiesta->account->email }}</div>
                                    @else
                                        <span class="text-muted fst-italic">Account eliminato</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($richiesta->stato === RichiestaIscrizione::STATO_APPROVATA)
                                        <span class="badge bg-success">Approvata</span>
                                        @if ($richiesta->socio)
                                            <span class="text-muted small d-block">Socio n. {{ $richiesta->socio->numero_socio }}</span>
                                        @endif
                                        @if (filled($richiesta->delibera_numero))
                                            <span class="text-muted small d-block">
                                                Delibera n. {{ $richiesta->delibera_numero }}@if ($richiesta->delibera_data) del {{ $richiesta->delibera_data->format('d/m/Y') }}@endif
                                            </span>
                                        @endif
                                    @else
                                        <span class="badge bg-danger">Rifiutata</span>
                                        @if (filled($richiesta->motivo_rifiuto))
                                            <span class="text-muted small d-block">{{ $richiesta->motivo_rifiuto }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    {{-- gestita_da null = approvazione automatica (modalità diretta) --}}
                                    {{ $richiesta->gestore?->nome_completo ?? 'Iscrizione diretta' }}
                                </td>
                                <td>{{ $richiesta->gestita_at?->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">Nessuna richiesta gestita finora.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($storico->hasPages())
            <div class="card-footer bg-white">
                {{ $storico->links() }}
            </div>
        @endif
    </div>

    {{-- ── Modale: approva ────────────────────────────────────────────── --}}
    <div class="modal fade" id="modalApprova" tabindex="-1" aria-labelledby="modalApprovaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="#" class="modal-content" id="formApprova">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovaLabel"><i class="bi bi-check-circle me-1"></i>Approva richiesta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <p>Approvare la richiesta di <strong data-ruolo="nome-richiedente">…</strong>?</p>
                    <p class="text-muted small mb-3">
                        Il socio preiscritto diventa <strong>Attivo</strong> e l'account del sito viene collegato.
                    </p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="genera_tessera" name="genera_tessera" value="1">
                        <label class="form-check-label" for="genera_tessera">
                            Genera subito la tessera per l'anno corrente
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="genera_quota" name="genera_quota" value="1" checked>
                        <label class="form-check-label" for="genera_quota">
                            Genera la quota associativa {{ now()->year }} (da pagare)
                            <span class="text-muted d-block small">Importo dal tipo socio o dall'associazione; se non configurato la quota non viene creata. Il link di pagamento viene incluso nell'email di benvenuto.</span>
                        </label>
                    </div>
                    {{-- Delibera di ammissione opzionale (f.14): finisce nel libro soci --}}
                    <div class="row">
                        <div class="col-md-6">
                            <label for="approva_delibera_numero" class="form-label small mb-1">Delibera di ammissione n. <span class="text-muted">(opzionale)</span></label>
                            <input type="text" class="form-control form-control-sm" id="approva_delibera_numero"
                                   name="delibera_numero" maxlength="50" value="{{ old('delibera_numero') }}" placeholder="es. 12/2026">
                        </div>
                        <div class="col-md-6">
                            <label for="approva_delibera_data" class="form-label small mb-1">Data delibera</label>
                            <input type="date" class="form-control form-control-sm" id="approva_delibera_data"
                                   name="delibera_data" value="{{ old('delibera_data') }}">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Approva</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Modale: rifiuta (motivo obbligatorio) ──────────────────────── --}}
    <div class="modal fade" id="modalRifiuta" tabindex="-1" aria-labelledby="modalRifiutaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="#" class="modal-content" id="formRifiuta">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="modalRifiutaLabel"><i class="bi bi-x-circle me-1"></i>Rifiuta richiesta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <p>Rifiutare la richiesta di <strong data-ruolo="nome-richiedente">…</strong>?</p>
                    <p class="text-muted small">
                        Il socio preiscritto creato dal form verrà eliminato; l'account resta e potrà
                        inviare una nuova richiesta.
                    </p>
                    <div class="mb-2">
                        <label for="motivo_rifiuto" class="form-label">Motivo del rifiuto <span class="text-danger">*</span></label>
                        <textarea class="form-control @error('motivo') is-invalid @enderror" id="motivo_rifiuto"
                                  name="motivo" rows="3" maxlength="500" required
                                  placeholder="Il motivo viene comunicato al richiedente via email.">{{ old('motivo') }}</textarea>
                        @error('motivo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Rifiuta</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Le modali sono condivise: il bottone della riga imposta action e nome
            function collega(selettoreBottoni, idForm) {
                document.querySelectorAll(selettoreBottoni).forEach(function (bottone) {
                    bottone.addEventListener('click', function () {
                        var form = document.getElementById(idForm);
                        form.setAttribute('action', bottone.dataset.action);
                        form.querySelectorAll('[data-ruolo="nome-richiedente"]').forEach(function (el) {
                            el.textContent = bottone.dataset.nome;
                        });
                    });
                });
            }

            collega('.btn-approva-richiesta', 'formApprova');
            collega('.btn-rifiuta-richiesta', 'formRifiuta');
        });
    </script>
@endpush
