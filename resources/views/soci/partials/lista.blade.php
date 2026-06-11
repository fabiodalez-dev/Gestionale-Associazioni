{{-- Tabella soci (renderizzata anche via AJAX — spec soci §1.10) --}}
@php
    $oggi = \Illuminate\Support\Carbon::today();
    $giorniNotifica = (int) ($associazione?->giorni_notifica_scadenza ?? 30);
    $badgeStato = fn (string $stato) => match ($stato) {
        'Attivo' => 'success',
        'In Attesa' => 'warning',
        'In Attesa di Pagamento' => 'info',
        'Sospeso' => 'secondary',
        default => 'danger',
    };
@endphp

@if ($soci->isEmpty())
    <div class="alert alert-light border text-center my-4">
        <i class="bi bi-search me-1"></i>Nessun socio trovato con i filtri correnti.
    </div>
@else
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Socio</th>
                    @if ($isSuperAdmin)
                        <th>Associazione</th>
                    @endif
                    <th>Contatti</th>
                    <th>Stato</th>
                    <th>Categoria / Tipo</th>
                    <th>Tessera</th>
                    <th>Tags</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($soci as $socio)
                    @php
                        $tessera = $socio->ultimaTessera;
                        $scadenza = $tessera?->data_scadenza;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('soci.show', $socio) }}" class="fw-semibold text-decoration-none">
                                {{ $socio->cognome }} {{ $socio->nome }}
                            </a>
                            <div class="text-muted small">N. {{ $socio->numero_socio }}</div>
                        </td>
                        @if ($isSuperAdmin)
                            <td class="small">{{ $socio->associazione?->nome }}</td>
                        @endif
                        <td class="small">
                            <div><i class="bi bi-envelope me-1"></i>{{ $socio->email }}</div>
                            @if ($socio->telefono)
                                <div><i class="bi bi-telephone me-1"></i>{{ $socio->telefono }}</div>
                            @endif
                        </td>
                        <td><span class="badge bg-{{ $badgeStato($socio->stato) }}">{{ $socio->stato }}</span></td>
                        <td class="small">
                            {{ $socio->categoriaSocio?->nome ?? '—' }}<br>
                            <span class="text-muted">{{ $socio->tipoSocio?->nome ?? '—' }}</span>
                        </td>
                        <td class="small">
                            @if ($tessera)
                                <div>N. {{ $tessera->numero_tessera }}</div>
                                @if ($tessera->template_tessera)
                                    <div class="text-muted">{{ $tessera->template_tessera }}</div>
                                @endif
                                @if ($scadenza)
                                    <div>
                                        {{ $scadenza->format('d/m/Y') }}
                                        @if ($scadenza->lt($oggi))
                                            <span class="badge bg-danger">Scaduta</span>
                                        @elseif ($scadenza->lte($oggi->copy()->addDays($giorniNotifica)))
                                            <span class="badge bg-warning text-dark">In scadenza</span>
                                        @endif
                                    </div>
                                @endif
                            @else
                                <span class="text-muted">Nessuna</span>
                            @endif
                        </td>
                        <td>
                            @foreach ($socio->tags as $tag)
                                <span class="badge" style="background-color: {{ $tag->colore }}">{{ $tag->nome_tag }}</span>
                            @endforeach
                        </td>
                        <td class="text-end text-nowrap">
                            @include('soci.partials.azioni', ['socio' => $socio])
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Vista card per mobile (stesso contenuto della tabella) --}}
    <div class="d-md-none">
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
                        </div>
                        <span class="badge bg-{{ $badgeStato($socio->stato) }}">{{ $socio->stato }}</span>
                    </div>
                    <div class="small mt-1">
                        <i class="bi bi-envelope me-1"></i>{{ $socio->email }}
                        @if ($socio->telefono)
                            · <i class="bi bi-telephone me-1"></i>{{ $socio->telefono }}
                        @endif
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
                            <span class="badge" style="background-color: {{ $tag->colore }}">{{ $tag->nome_tag }}</span>
                        @endforeach
                    </div>
                    <div class="mt-2 text-end">
                        @include('soci.partials.azioni', ['socio' => $socio])
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex justify-content-center mt-3">
        {{ $soci->links() }}
    </div>
@endif
