@extends('pubblico.layouts.app')

@section('title', 'Tessera e quote — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Tessera &amp; quote</h1>

    @include('pubblico.account._nav')

    <div class="row g-4">
        {{-- Tessera attiva con QR --}}
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-card-heading me-1"></i>La mia tessera</div>
                <div class="card-body text-center">
                    @if ($tessera !== null)
                        <div class="fw-semibold fs-5">{{ $socio->nome }} {{ $socio->cognome }}</div>
                        <div class="text-muted small mb-3">
                            Tessera n. <span class="font-monospace">{{ $tessera->numero_tessera }}</span>
                            &middot; anno {{ $tessera->anno_validita }}
                        </div>
                        @if ($tesseraQr !== null)
                            <img src="{{ $tesseraQr }}" alt="QR tessera {{ $tessera->numero_tessera }}"
                                 class="img-fluid bg-white p-2 rounded border mb-3" style="max-width: 220px;">
                            <p class="text-muted small mb-0">Mostra questo QR per il check-in da socio.</p>
                        @endif
                        <div class="small text-muted mt-2">
                            Valida fino al {{ $tessera->data_scadenza?->format('d/m/Y') }}
                        </div>
                    @else
                        <p class="text-muted py-4 mb-0">
                            <i class="bi bi-card-heading display-6 d-block mb-2 opacity-50"></i>
                            Nessuna tessera attiva al momento.
                        </p>
                    @endif

                    {{-- Rinnovo self-service (f.1 piano Vita sociale — modulo rinnovi) --}}
                    @include('rinnovi.partials.btn-rinnova-account', ['associazione' => $associazione, 'socio' => $socio])
                </div>
            </div>
        </div>

        {{-- Quote con link pagamento --}}
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-cash-coin me-1"></i>Le mie quote</div>
                <div class="card-body p-0">
                    @if ($quote->isEmpty())
                        <p class="text-muted text-center py-4 mb-0">Nessuna quota registrata.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Anno</th>
                                        <th>Tipo</th>
                                        <th>Importo</th>
                                        <th>Scadenza</th>
                                        <th>Stato</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($quote as $quota)
                                        @php $stato = $quota->statoCalcolato($giorniPreavviso); @endphp
                                        <tr>
                                            <td>{{ $quota->anno }}</td>
                                            <td>{{ $quota->tipo }}</td>
                                            <td class="text-nowrap">&euro; {{ number_format((float) $quota->importo, 2, ',', '.') }}</td>
                                            <td class="text-nowrap">{{ $quota->data_scadenza?->format('d/m/Y') }}</td>
                                            <td>
                                                @if ($stato === \App\Models\Quota::STATO_PAGATA)
                                                    <span class="badge text-bg-success">Pagata</span>
                                                @elseif ($stato === \App\Models\Quota::STATO_SCADUTA)
                                                    <span class="badge text-bg-danger">Scaduta</span>
                                                @elseif ($stato === \App\Models\Quota::STATO_IN_SCADENZA)
                                                    <span class="badge text-bg-warning">In scadenza</span>
                                                @else
                                                    <span class="badge text-bg-secondary">Da pagare</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($linkPagamento->has($quota->id))
                                                    <a href="{{ $linkPagamento->get($quota->id) }}" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-credit-card me-1"></i>Paga ora
                                                    </a>
                                                @endif
                                                @if ($quota->isPagata() && \Illuminate\Support\Facades\Route::has('pubblico.account.quote.ricevuta'))
                                                    <a href="{{ route('pubblico.account.quote.ricevuta', ['associazione' => $associazione->slug, 'quota' => $quota->id]) }}"
                                                       class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-file-earmark-pdf me-1"></i>Ricevuta
                                                    </a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
