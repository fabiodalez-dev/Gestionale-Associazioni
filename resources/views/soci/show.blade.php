@extends('layouts.app')

@section('title', $socio->nome_completo.' - '.session('app_name', config('app.name')))

@section('content')
    @php
        $tab = request('tab', 'anagrafica');
        $badgeStato = match ($socio->stato) {
            'Attivo' => 'success',
            'In Attesa' => 'warning',
            'In Attesa di Pagamento' => 'info',
            'Sospeso' => 'secondary',
            default => 'danger',
        };
        // f.23: badge documenti scaduti / in scadenza nella scheda socio
        $documentiScaduti = $socio->documenti->filter->isScaduto();
        $documentiInScadenza = $socio->documenti->filter->isInScadenza();
    @endphp

    @php
        $haFoto = $socio->getFirstMedia(\App\Models\Socio::COLLEZIONE_FOTO) !== null;
        $tesseraCorrente = $socio->ultimaTessera;
        $associazioneSocio = $socio->associazione;
        $inizialiSocio = mb_strtoupper(mb_substr((string) $socio->nome, 0, 1).mb_substr((string) $socio->cognome, 0, 1));
        $versatoTotale = (float) $socio->quote()->where('stato', 'Pagata')->sum('importo');
        $anniSocio = $socio->data_iscrizione ? (int) $socio->data_iscrizione->diffInYears(now()) : null;
        $numeroTessere = $socio->tessere()->count();
    @endphp

    <div class="profile-hero">
        <div class="profile-cover"></div>
        <div class="profile-main">
            @if ($haFoto)
                <img src="{{ route('soci.foto.show', $socio) }}" alt="Foto di {{ $socio->nome }} {{ $socio->cognome }}" class="avatar avatar-xl" style="object-fit: cover">
            @else
                <span class="avatar avatar-xl tone-{{ abs(crc32((string) $socio->id)) % 6 }}" aria-hidden="true">{{ $inizialiSocio }}</span>
            @endif
            <div class="profile-id">
                <h1>
                    {{ $socio->nome_completo }}
                    <span class="badge bg-{{ $badgeStato }}">{{ $socio->stato }}</span>
                    @if ($socio->isMinorenne())
                        <span class="badge bg-info" title="Socio minorenne (f.22)"><i class="bi bi-person-exclamation"></i>Minorenne</span>
                    @endif
                    @if ($documentiScaduti->isNotEmpty())
                        <span class="badge bg-danger" title="Documenti scaduti"><i class="bi bi-exclamation-triangle"></i>{{ $documentiScaduti->count() }} {{ $documentiScaduti->count() === 1 ? 'documento scaduto' : 'documenti scaduti' }}</span>
                    @endif
                    @if ($documentiInScadenza->isNotEmpty())
                        <span class="badge bg-warning" title="Documenti in scadenza"><i class="bi bi-hourglass-split"></i>{{ $documentiInScadenza->count() }} in scadenza</span>
                    @endif
                    {{-- Contatore "porta un amico" (f.23 piano "Organizzazione 3" — modulo relazioni) --}}
                    @includeIf('relazioni.soci._referral-badge', ['socio' => $socio])
                </h1>
                <div class="profile-facts">
                    <span><i class="bi bi-person-vcard"></i>Socio n. {{ $socio->numero_socio }}</span>
                    @if ($socio->citta)
                        <span><i class="bi bi-geo-alt"></i>{{ $socio->citta }}{{ $socio->provincia ? ' ('.$socio->provincia.')' : '' }}</span>
                    @endif
                    @if ($socio->data_iscrizione)
                        <span><i class="bi bi-calendar3"></i>Socio dal {{ $socio->data_iscrizione->locale('it')->translatedFormat('F Y') }}</span>
                    @endif
                    @if ($socio->sede)
                        <span><i class="bi bi-building"></i>{{ $socio->sede->nome }}</span>
                    @endif
                </div>
                @if ($socio->tags->isNotEmpty())
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        @foreach ($socio->tags as $tag)
                            <span class="badge tag-chip" style="--tag: {{ $tag->colore ?: '#8A90A0' }}">{{ $tag->nome_tag }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="profile-actions">
                {{-- Contatti rapidi (f.10) + email diretta (f.12) — modulo comunicazione --}}
                @includeIf('comunicazione.soci._contatti-rapidi', ['socio' => $socio, 'dimensione' => 'md'])
                @includeIf('comunicazione.soci._invia-email', ['socio' => $socio])
                @can('esportaDatiPersonali', $socio)
                    <a href="{{ route('soci.dati-personali', $socio) }}" class="btn btn-outline-secondary" title="Archivio ZIP con tutti i dati personali del socio (richiesta di accesso GDPR)"><i class="bi bi-file-earmark-zip"></i>Dati personali</a>
                @endcan
                <a href="{{ route('soci.edit', $socio) }}" class="btn btn-primary"><i class="bi bi-pencil"></i>Modifica</a>
            </div>
        </div>
    @php
        $tabs = ['anagrafica' => 'Anagrafica', 'quote-tessere' => 'Quote e Tessere', 'tesseramento' => 'Tesseramento', 'vita-sociale' => 'Vita sociale', 'documenti' => 'Documenti'];
        // Tab Note interne (f.3 piano "Gestione 2" — modulo operativita)
        if (\Illuminate\Support\Facades\Route::has('soci.note.store')) {
            $tabs['note'] = 'Note';
        }
        // Tab Volontariato (f.1/f.2/f.5 piano "Organizzazione 3" — modulo volontari-turni)
        if (\Illuminate\Support\Facades\Route::has('volontari.profilo.update')) {
            $tabs['volontariato'] = 'Volontariato';
        }
        // Tab Prestiti attrezzature (f.10 piano "Organizzazione 3" — modulo spazi-risorse)
        if (\Illuminate\Support\Facades\Route::has('prestiti.index')) {
            $tabs['prestiti'] = 'Prestiti';
        }
        $tabs['storico'] = 'Storico';
    @endphp
        <ul class="nav nav-tabs" role="tablist">
            @foreach ($tabs as $key => $label)
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $tab === $key ? 'active' : '' }}" data-bs-toggle="tab"
                            data-bs-target="#tab-{{ $key }}" type="button" role="tab">{{ $label }}</button>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="tab-content mb-4">
        {{-- Tab Anagrafica --}}
        <div class="tab-pane fade {{ $tab === 'anagrafica' ? 'show active' : '' }}" id="tab-anagrafica" role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-5 d-flex flex-column gap-4">
                    {{-- Tessera digitale --}}
                    @if ($tesseraCorrente)
                        @php
                            $tesseraValida = $tesseraCorrente->stato === 'Attiva' && ($tesseraCorrente->data_scadenza === null || $tesseraCorrente->data_scadenza->gte(now()->startOfDay()));
                        @endphp
                        <div class="member-card">
                            <div class="mc-assoc">
                                <span class="avatar avatar-sm avatar-square">{{ mb_strtoupper(mb_substr((string) $associazioneSocio?->nome, 0, 2)) }}</span>
                                <div>
                                    <b>{{ $associazioneSocio?->nome }}</b>
                                    <small>Tessera socio {{ $tesseraCorrente->anno_validita }}</small>
                                </div>
                                <span class="mc-state">{{ $tesseraValida ? 'Valida' : $tesseraCorrente->stato }}</span>
                            </div>
                            <div class="mc-name">{{ $socio->nome_completo }}</div>
                            <div class="mc-num">{{ $tesseraCorrente->numero_tessera }}</div>
                            <div class="mc-meta">
                                {{ $socio->categoriaSocio?->nome ?? $socio->tipoSocio?->nome ?? 'Socio' }}
                                @if ($tesseraCorrente->data_scadenza)
                                    · scade {{ $tesseraCorrente->data_scadenza->format('d/m/Y') }}
                                @endif
                            </div>
                            @php
                                $qrTessera = rescue(fn () => app(\App\Services\Tessere\TesseraQrService::class)->generateDataUri(app(\App\Services\Tessere\TesseraQrService::class)->verificationUrl($tesseraCorrente)), null, false);
                            @endphp
                            @if ($qrTessera)
                                <span class="mc-qr"><img src="{{ $qrTessera }}" alt="QR di verifica della tessera {{ $tesseraCorrente->numero_tessera }}"></span>
                            @endif
                        </div>
                    @else
                        <div class="member-card is-empty">
                            <div class="fw-semibold text-body">Nessuna tessera</div>
                            <div class="small mt-1">Il socio non ha ancora una tessera emessa.</div>
                            @if (\Illuminate\Support\Facades\Route::has('tessere.index'))
                                <a href="{{ route('tessere.index') }}" class="btn btn-sm btn-soft mt-3">Vai alle tessere</a>
                            @endif
                        </div>
                    @endif

                    <div class="row g-3">
                        <div class="col-6"><div class="mini-stat"><div class="l">Anni da socio</div><div class="v">{{ $anniSocio ?? '—' }}</div></div></div>
                        <div class="col-6"><div class="mini-stat"><div class="l">Versato totale</div><div class="v">€ {{ number_format($versatoTotale, $versatoTotale >= 1000 ? 0 : 2, ',', '.') }}</div></div></div>
                        <div class="col-6"><div class="mini-stat"><div class="l">Tessere emesse</div><div class="v">{{ $numeroTessere }}</div></div></div>
                        <div class="col-6"><div class="mini-stat"><div class="l">Documenti</div><div class="v">{{ $socio->documenti->count() }}</div></div></div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="section-label">Foto</div>
                            <div class="d-flex flex-wrap align-items-start gap-3">
                @can('update', $socio)
                                <form method="POST" action="{{ route('soci.foto.store', $socio) }}" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-start">
                                    @csrf
                                    <div>
                                        <label for="foto" class="form-label small mb-1">{{ $haFoto ? 'Sostituisci foto' : 'Foto per la tessera' }}</label>
                                        <input type="file" name="foto" id="foto" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm @error('foto') is-invalid @enderror" required>
                                        @error('foto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline-primary mt-4"><i class="bi bi-upload me-1"></i>Carica</button>
                                </form>
                                @if ($haFoto)
                                    <form method="POST" action="{{ route('soci.foto.destroy', $socio) }}" class="mt-4" onsubmit="return confirm('Rimuovere la foto?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Rimuovi</button>
                                    </form>
                                @endif
                            @endcan
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 d-flex flex-column gap-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="section-label">Dati personali</div>
                            <dl class="kv">
                                <dt>Email</dt><dd>{{ $socio->email ?: 'N/D' }}</dd>
                                <dt>Telefono</dt><dd>{{ $socio->telefono ?: 'N/D' }}</dd>
                                <dt>Codice Fiscale</dt><dd>{{ $socio->codice_fiscale ?: 'N/D' }}</dd>
                                <dt>Data di Nascita</dt><dd>{{ $socio->data_nascita?->format('d/m/Y') ?? 'N/D' }}</dd>
                                @if ($socio->isMinorenne() || $socio->hasTutore())
                                    <dt>Tutore</dt>
                                    <dd>
                                        {{ $socio->tutore_nome ?: 'N/D' }}
                                        @if ($socio->tutore_telefono)<span class="text-muted">· <i class="bi bi-telephone me-1"></i>{{ $socio->tutore_telefono }}</span>@endif
                                        @if ($socio->tutore_email)<span class="text-muted">· <i class="bi bi-envelope me-1"></i>{{ $socio->tutore_email }}</span>@endif
                                    </dd>
                                @endif
                                <dt>Data Iscrizione</dt><dd>{{ $socio->data_iscrizione?->format('d/m/Y') ?? 'N/D' }}</dd>
                                <dt>Indirizzo</dt>
                                <dd>{{ trim(($socio->indirizzo ?? '').' '.($socio->cap ?? '').' '.($socio->citta ?? '').' '.($socio->provincia ?? '')) ?: 'N/D' }}</dd>
                                <dt>Tipo Socio</dt><dd>{{ $socio->tipoSocio?->nome ?? 'N/D' }}</dd>
                                <dt>Categoria</dt><dd>{{ $socio->categoriaSocio?->nome ?? 'N/D' }}</dd>
                                <dt>Sede</dt><dd>{{ $socio->sede?->nome ?? 'N/D' }}</dd>
                                <dt>Consenso Privacy</dt><dd>{{ $socio->privacy_consenso ? 'Sì' : 'No' }}</dd>
                                @if ($socio->note)
                                    <dt>Note</dt><dd>{{ $socio->note }}</dd>
                                @endif
                                @foreach ($socio->valoriCampiPersonalizzati as $valore)
                                    @if ($valore->campo && filled($valore->valore))
                                        <dt>{{ $valore->campo->nome_campo }}</dt>
                                        <dd>{{ $valore->campo->tipo_campo === 'checkbox' ? ($valore->valore === '1' ? 'Sì' : 'No') : $valore->valore }}</dd>
                                    @endif
                                @endforeach
                            </dl>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            @php
                                $consensiSocio = $socio->consensi()->latest()->get()->unique('chiave');
                            @endphp
                            @if ($consensiSocio->isNotEmpty())
                                <div class="section-label">Consensi</div>
                                <ul class="consent-list">
                                    @foreach ($consensiSocio as $consenso)
                                        <li>
                                            <div>
                                                <div class="text-body">{{ $consenso->testo }}</div>
                                                <div class="who-sub">{{ $consenso->created_at->format('d/m/Y H:i') }} · {{ str_replace('_', ' ', $consenso->origine) }}</div>
                                            </div>
                                            <span class="badge {{ $consenso->accettato ? 'bg-success' : 'bg-secondary' }}">
                                                <span class="visually-hidden">{{ $consenso->accettato ? 'Dato' : 'Non dato' }}:</span>{{ $consenso->accettato ? 'Dato' : 'Negato' }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($consensiSocio->isEmpty())
                                <div class="section-label">Consensi</div>
                                <p class="text-muted small mb-0">Nessun consenso registrato dai moduli online.</p>
                            @endif
                        </div>
                    </div>

                    {{-- Nucleo familiare (f.20 piano "Gestione 2" — modulo membri) --}}
                    @if (\Illuminate\Support\Facades\Route::has('nuclei.store'))
                        @include('membri.partials.nucleo-socio', ['socio' => $socio])
                    @endif
                </div>
            </div>
        </div>

        {{-- Tab Quote e Tessere --}}
        <div class="tab-pane fade {{ $tab === 'quote-tessere' ? 'show active' : '' }}" id="tab-quote-tessere" role="tabpanel">
            <h5>Quote</h5>
            @if ($socio->quote->isEmpty())
                <p class="text-muted">Nessuna quota registrata.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Anno</th><th>Importo</th><th>Stato</th><th>Data pagamento</th></tr></thead>
                        <tbody>
                            @foreach ($socio->quote as $quota)
                                <tr>
                                    <td>{{ $quota->anno }}</td>
                                    <td>€ {{ number_format((float) $quota->importo, 2, ',', '.') }}</td>
                                    <td><span class="badge bg-{{ $quota->stato === 'Pagata' ? 'success' : 'warning text-dark' }}">{{ $quota->stato }}</span></td>
                                    <td>{{ $quota->data_pagamento?->format('d/m/Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h5 class="mt-4">Tessere</h5>
            @if ($socio->tessere->isEmpty())
                <p class="text-muted">Nessuna tessera emessa.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Numero</th><th>Anno</th><th>Scadenza</th><th>Stato</th><th>Costo</th><th>Verifica QR</th></tr></thead>
                        <tbody>
                            @foreach ($socio->tessere as $tessera)
                                <tr>
                                    <td>{{ $tessera->numero_tessera }}</td>
                                    <td>{{ $tessera->anno_validita }}</td>
                                    <td>{{ $tessera->data_scadenza?->format('d/m/Y') ?? '—' }}</td>
                                    <td><span class="badge bg-{{ $tessera->stato === 'Attiva' ? 'success' : 'secondary' }}">{{ $tessera->stato }}</span></td>
                                    <td>{{ $costoTessera !== null ? '€ '.number_format((float) $costoTessera, 2, ',', '.') : '—' }}</td>
                                    <td>
                                        @if ($tessera->qr_code_url)
                                            <a href="{{ $tessera->qr_code_url }}" target="_blank" rel="noopener" class="small">
                                                <i class="bi bi-qr-code me-1"></i>Link verifica
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Tab Documenti --}}
        <div class="tab-pane fade {{ $tab === 'documenti' ? 'show active' : '' }}" id="tab-documenti" role="tabpanel">
            <form method="POST" action="{{ route('soci.documenti.store', $socio) }}" enctype="multipart/form-data" class="row g-2 align-items-end mb-4">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="doc_file">File *</label>
                    <input type="file" class="form-control" id="doc_file" name="file" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="doc_descrizione">Descrizione</label>
                    <input type="text" class="form-control" id="doc_descrizione" name="descrizione" maxlength="1000">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="doc_tipo">Tipo</label>
                    <select class="form-select" id="doc_tipo" name="tipo_documento">
                        <option value="">—</option>
                        @foreach (\App\Models\DocumentoSocio::TIPI_DOCUMENTO as $tipoKey => $tipoLabel)
                            <option value="{{ $tipoKey }}">{{ $tipoLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="doc_scadenza">Scadenza</label>
                    <input type="date" class="form-control" id="doc_scadenza" name="data_scadenza">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>Carica</button>
                </div>
            </form>

            @if ($socio->documenti->isEmpty())
                <p class="text-muted">Nessun documento caricato.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>File</th><th>Descrizione</th><th>Tipo</th><th>Scadenza</th><th>Caricato il</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                            @foreach ($socio->documenti as $documento)
                                <tr>
                                    <td>{{ $documento->nome_file }}</td>
                                    <td>{{ $documento->descrizione ?: '—' }}</td>
                                    <td>{{ $documento->tipo_documento ? (\App\Models\DocumentoSocio::TIPI_DOCUMENTO[$documento->tipo_documento] ?? $documento->tipo_documento) : '—' }}</td>
                                    <td>
                                        {{ $documento->data_scadenza?->format('d/m/Y') ?? '—' }}
                                        @if ($documento->isScaduto())
                                            <span class="badge bg-danger">Scaduto</span>
                                        @elseif ($documento->isInScadenza())
                                            <span class="badge bg-warning text-dark">In scadenza</span>
                                        @endif
                                    </td>
                                    <td>{{ $documento->created_at?->format('d/m/Y H:i') }}</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('soci.documenti.download', [$socio, $documento]) }}" class="btn btn-sm btn-outline-primary" title="Scarica">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <form method="POST" action="{{ route('soci.documenti.destroy', [$socio, $documento]) }}" class="d-inline"
                                              onsubmit="return confirm('Eliminare definitivamente questo documento?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Tab Tesseramento (f.6 piano Vita sociale — modulo rinnovi) --}}
        <div class="tab-pane fade {{ $tab === 'tesseramento' ? 'show active' : '' }}" id="tab-tesseramento" role="tabpanel">
            @include('rinnovi.partials.tab-tesseramento', ['socio' => $socio])
        </div>

        {{-- Tab Vita sociale (f.12 piano Vita sociale — modulo eventi-vita) --}}
        <div class="tab-pane fade {{ $tab === 'vita-sociale' ? 'show active' : '' }}" id="tab-vita-sociale" role="tabpanel">
            @include('eventi-vita._vita-sociale-socio', ['socio' => $socio])
        </div>

        {{-- Tab Note interne (f.3 piano "Gestione 2" — modulo operativita) --}}
        @if (\Illuminate\Support\Facades\Route::has('soci.note.store'))
            <div class="tab-pane fade {{ $tab === 'note' ? 'show active' : '' }}" id="tab-note" role="tabpanel">
                @includeIf('operativita._tab-note', ['socio' => $socio])
            </div>
        @endif

        {{-- Tab Prestiti attrezzature (f.10 piano "Organizzazione 3" — modulo spazi-risorse) --}}
        @if (\Illuminate\Support\Facades\Route::has('prestiti.index'))
            <div class="tab-pane fade {{ $tab === 'prestiti' ? 'show active' : '' }}" id="tab-prestiti" role="tabpanel">
                @includeIf('spazi-risorse.partials.prestiti-socio', ['socio' => $socio])
            </div>
        @endif

        {{-- Tab Volontariato (f.1/f.2/f.5 piano "Organizzazione 3" — modulo volontari-turni) --}}
        @if (\Illuminate\Support\Facades\Route::has('volontari.profilo.update'))
            <div class="tab-pane fade {{ $tab === 'volontariato' ? 'show active' : '' }}" id="tab-volontariato" role="tabpanel">
                @includeIf('volontari-turni.partials._tab-volontariato', ['socio' => $socio])
            </div>
        @endif

        {{-- Tab Storico --}}
        <div class="tab-pane fade {{ $tab === 'storico' ? 'show active' : '' }}" id="tab-storico" role="tabpanel">
            @if ($socio->storicoAttivita->isEmpty())
                <p class="text-muted">Nessuna attività registrata.</p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($socio->storicoAttivita as $attivita)
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between">
                                <strong>{{ $attivita->tipo_attivita }}</strong>
                                <span class="text-muted small">{{ $attivita->data_attivita?->format('d/m/Y H:i') }}</span>
                            </div>
                            <div>{{ $attivita->descrizione }}</div>
                            @if ($attivita->utente)
                                <div class="text-muted small">Eseguito da: {{ $attivita->utente->email }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
