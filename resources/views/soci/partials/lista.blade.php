{{-- Tabella soci (renderizzata anche via AJAX — spec soci §1.10) --}}
@php
    $oggi = \Illuminate\Support\Carbon::today();
    $giorniNotifica = $associazione?->tesseramento()->giorni_notifica_scadenza ?? \App\Settings\TesseramentoSettings::GIORNI_PREAVVISO_DEFAULT;
    $badgeStato = fn (string $stato) => match ($stato) {
        'Attivo' => 'success',
        'In Attesa' => 'warning',
        'In Attesa di Pagamento' => 'info',
        'Sospeso' => 'secondary',
        default => 'danger',
    };
@endphp

@if ($soci->isEmpty())
    <div class="empty-state">
        <span class="icon-tile tone-4"><i class="bi bi-search"></i></span>
        <h3>Nessun socio trovato</h3>
        <p class="mb-0">Prova a cambiare o azzerare i filtri.</p>
    </div>
@else
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    {{-- Selezione per azioni in blocco (f.2 piano "Gestione 2" — modulo operativita) --}}
                    @if (\Illuminate\Support\Facades\Route::has('soci.azioni-blocco'))
                        <th style="width: 2rem">
                            <input type="checkbox" class="form-check-input" id="selectAllSoci" aria-label="Seleziona tutti i soci">
                        </th>
                    @endif
                    <th>Socio</th>
                    @if ($isSuperAdmin)
                        <th>Associazione</th>
                    @endif
                    <th>Tessera</th>
                    <th>Categoria</th>
                    <th>Stato</th>
                    <th>Contatti</th>
                    <th class="text-end"><span class="visually-hidden">Azioni</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($soci as $socio)
                    @php
                        $tessera = $socio->ultimaTessera;
                        $scadenza = $tessera?->data_scadenza;
                        $inizialiSocio = mb_strtoupper(mb_substr((string) $socio->nome, 0, 1).mb_substr((string) $socio->cognome, 0, 1));
                        $tono = abs(crc32((string) $socio->id)) % 6;
                    @endphp
                    <tr>
                        @if (\Illuminate\Support\Facades\Route::has('soci.azioni-blocco'))
                            <td>
                                <input type="checkbox" class="form-check-input socio-check" value="{{ $socio->id }}"
                                       aria-label="Seleziona {{ $socio->cognome }} {{ $socio->nome }}">
                            </td>
                        @endif
                        <td>
                            <div class="who-cell">
                                <span class="avatar tone-{{ $tono }}">{{ $inizialiSocio }}</span>
                                <div class="min-w-0">
                                    <a href="{{ route('soci.show', $socio) }}" class="who-name">{{ $socio->cognome }} {{ $socio->nome }}</a>
                                    @if ($socio->isMinorenne())
                                        <span class="badge bg-info ms-1" title="Socio minorenne">Minorenne</span>
                                    @endif
                                    <div class="who-sub">
                                        {{ $socio->email ?: 'N. '.$socio->numero_socio }}
                                        @if ($socio->nucleo)
                                            · <i class="bi bi-house-heart" title="Nucleo familiare"></i> {{ $socio->nucleo->nome }}
                                        @endif
                                    </div>
                                    @if ($socio->tags->isNotEmpty())
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            @foreach ($socio->tags as $tag)
                                                <span class="badge tag-chip" style="--tag: {{ $tag->colore ?: '#8A90A0' }}">{{ $tag->nome_tag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        @if ($isSuperAdmin)
                            <td>{{ $socio->associazione?->nome }}</td>
                        @endif
                        <td>
                            @if ($tessera)
                                <div class="mono text-body">{{ $tessera->numero_tessera }}</div>
                                <div class="who-sub text-nowrap">
                                    @if ($scadenza)
                                        scade {{ $scadenza->format('d/m/Y') }}
                                        @if ($scadenza->lt($oggi))
                                            <span class="badge bg-danger ms-1">Scaduta</span>
                                        @elseif ($scadenza->lte($oggi->copy()->addDays($giorniNotifica)))
                                            <span class="badge bg-warning ms-1">In scadenza</span>
                                        @endif
                                    @else
                                        {{ $tessera->template_tessera ?: 'senza scadenza' }}
                                    @endif
                                </div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($socio->categoriaSocio)
                                <div class="text-body">{{ $socio->categoriaSocio->nome }}</div>
                            @endif
                            @if ($socio->tipoSocio)
                                <div class="who-sub">{{ $socio->tipoSocio->nome }}</div>
                            @endif
                            <div class="who-sub">N. {{ $socio->numero_socio }}</div>
                        </td>
                        <td><span class="badge bg-{{ $badgeStato($socio->stato) }}">{{ $socio->stato }}</span></td>
                        <td class="text-nowrap">
                            {{-- Contatti rapidi (f.10 piano Gestione 2 — modulo comunicazione) --}}
                            @includeIf('comunicazione.soci._contatti-rapidi', ['socio' => $socio])
                        </td>
                        <td class="text-end text-nowrap row-actions">
                            @include('soci.partials.azioni', ['socio' => $socio])
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Vista card per mobile (stesso contenuto della tabella) --}}
    <div class="d-md-none p-3">
        @foreach ($soci as $socio)
            @php
                $tessera = $socio->ultimaTessera;
                $scadenza = $tessera?->data_scadenza;
            @endphp
            <div class="card mb-2">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <a href="{{ route('soci.show', $socio) }}" class="fw-semibold text-decoration-none">
                                {{ $socio->cognome }} {{ $socio->nome }}
                            </a>
                            <span class="text-muted small">— N. {{ $socio->numero_socio }}</span>
                            @if ($socio->isMinorenne())
                                <span class="badge bg-info text-dark" title="Socio minorenne">Minorenne</span>
                            @endif
                            @if ($socio->nucleo)
                                <span class="text-muted small">· <i class="bi bi-house-heart"></i> {{ $socio->nucleo->nome }}</span>
                            @endif
                        </div>
                        <span class="badge bg-{{ $badgeStato($socio->stato) }}">{{ $socio->stato }}</span>
                    </div>
                    <div class="small mt-1">
                        <i class="bi bi-envelope me-1"></i>{{ $socio->email }}
                        @if ($socio->telefono)
                            · <i class="bi bi-telephone me-1"></i>{{ $socio->telefono }}
                        @endif
                        {{-- Contatti rapidi (f.10 piano Gestione 2 — modulo comunicazione) --}}
                        <span class="ms-1">@includeIf('comunicazione.soci._contatti-rapidi', ['socio' => $socio])</span>
                    </div>
                    @if ($tessera)
                        <div class="small text-muted mt-1">
                            Tessera n. {{ $tessera->numero_tessera }}
                            @if ($scadenza)
                                — scade il {{ $scadenza->format('d/m/Y') }}
                                @if ($scadenza->lt($oggi))
                                    <span class="badge bg-danger">Scaduta</span>
                                @endif
                            @endif
                        </div>
                    @endif
                    <div class="mt-1">
                        @foreach ($socio->tags as $tag)
                            <span class="badge tag-chip" style="--tag: {{ $tag->colore ?: '#8A90A0' }}">{{ $tag->nome_tag }}</span>
                        @endforeach
                    </div>
                    <div class="mt-2 text-end">
                        @include('soci.partials.azioni', ['socio' => $socio])
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($soci->hasPages())
        <div class="list-foot">{{ $soci->onEachSide(1)->links() }}</div>
    @endif
@endif
