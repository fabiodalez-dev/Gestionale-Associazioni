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

    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-1">{{ $socio->nome_completo }}</h1>
            <p class="text-muted mb-1">Socio n. {{ $socio->numero_socio }}
                <span class="badge bg-{{ $badgeStato }}">{{ $socio->stato }}</span>
                @if ($documentiScaduti->isNotEmpty())
                    <span class="badge bg-danger" title="Documenti scaduti"><i class="bi bi-exclamation-triangle me-1"></i>{{ $documentiScaduti->count() }} {{ $documentiScaduti->count() === 1 ? 'documento scaduto' : 'documenti scaduti' }}</span>
                @endif
                @if ($documentiInScadenza->isNotEmpty())
                    <span class="badge bg-warning text-dark" title="Documenti in scadenza"><i class="bi bi-hourglass-split me-1"></i>{{ $documentiInScadenza->count() }} in scadenza</span>
                @endif
            </p>
            <div>
                @foreach ($socio->tags as $tag)
                    <span class="badge" style="background-color: {{ $tag->colore }}">{{ $tag->nome_tag }}</span>
                @endforeach
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('soci.edit', $socio) }}" class="btn btn-primary"><i class="bi bi-pencil me-1"></i>Modifica</a>
            <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Lista</a>
        </div>
    </div>

    <ul class="nav nav-tabs" role="tablist">
        @foreach (['anagrafica' => 'Anagrafica', 'quote-tessere' => 'Quote e Tessere', 'tesseramento' => 'Tesseramento', 'vita-sociale' => 'Vita sociale', 'documenti' => 'Documenti', 'storico' => 'Storico'] as $key => $label)
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $tab === $key ? 'active' : '' }}" data-bs-toggle="tab"
                        data-bs-target="#tab-{{ $key }}" type="button" role="tab">{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white mb-4">
        {{-- Tab Anagrafica --}}
        <div class="tab-pane fade {{ $tab === 'anagrafica' ? 'show active' : '' }}" id="tab-anagrafica" role="tabpanel">
            <dl class="row mb-0">
                <dt class="col-sm-3">Email</dt><dd class="col-sm-9">{{ $socio->email ?: 'N/D' }}</dd>
                <dt class="col-sm-3">Telefono</dt><dd class="col-sm-9">{{ $socio->telefono ?: 'N/D' }}</dd>
                <dt class="col-sm-3">Codice Fiscale</dt><dd class="col-sm-9">{{ $socio->codice_fiscale ?: 'N/D' }}</dd>
                <dt class="col-sm-3">Data di Nascita</dt><dd class="col-sm-9">{{ $socio->data_nascita?->format('d/m/Y') ?? 'N/D' }}</dd>
                <dt class="col-sm-3">Data Iscrizione</dt><dd class="col-sm-9">{{ $socio->data_iscrizione?->format('d/m/Y') ?? 'N/D' }}</dd>
                <dt class="col-sm-3">Indirizzo</dt>
                <dd class="col-sm-9">{{ trim(($socio->indirizzo ?? '').' '.($socio->cap ?? '').' '.($socio->citta ?? '').' '.($socio->provincia ?? '')) ?: 'N/D' }}</dd>
                <dt class="col-sm-3">Tipo Socio</dt><dd class="col-sm-9">{{ $socio->tipoSocio?->nome ?? 'N/D' }}</dd>
                <dt class="col-sm-3">Categoria</dt><dd class="col-sm-9">{{ $socio->categoriaSocio?->nome ?? 'N/D' }}</dd>
                <dt class="col-sm-3">Sede</dt><dd class="col-sm-9">{{ $socio->sede?->nome ?? 'N/D' }}</dd>
                <dt class="col-sm-3">Consenso Privacy</dt><dd class="col-sm-9">{{ $socio->privacy_consenso ? 'Sì' : 'No' }}</dd>
                @if ($socio->note)
                    <dt class="col-sm-3">Note</dt><dd class="col-sm-9">{{ $socio->note }}</dd>
                @endif
                @foreach ($socio->valoriCampiPersonalizzati as $valore)
                    @if ($valore->campo && filled($valore->valore))
                        <dt class="col-sm-3">{{ $valore->campo->nome_campo }}</dt>
                        <dd class="col-sm-9">{{ $valore->campo->tipo_campo === 'checkbox' ? ($valore->valore === '1' ? 'Sì' : 'No') : $valore->valore }}</dd>
                    @endif
                @endforeach
            </dl>
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
