{{-- Azioni per riga quota (tabella desktop e card mobile) --}}
@php
    $socioNome = $quota->socio ? trim($quota->socio->cognome.' '.$quota->socio->nome) : '—';
    $payData = json_encode([
        'pay_url' => route('quote.pagamento', $quota),
        'socio_nome' => $socioNome,
        'importo' => number_format((float) $quota->importo, 2, ',', '.'),
    ]);
    $editData = json_encode([
        'update_url' => route('quote.update', $quota),
        'socio_id' => $quota->socio_id,
        'socio_nome' => $socioNome,
        'anno' => $quota->anno,
        'importo' => (string) $quota->importo,
        'data_scadenza' => $quota->data_scadenza?->toDateString(),
        'tipo' => $quota->tipo,
    ]);
@endphp
<div class="btn-group btn-group-sm" role="group">
    @if ($stato !== \App\Models\Quota::STATO_PAGATA)
        @can('registraPagamento', $quota)
            <button type="button"
                    class="btn btn-outline-success"
                    data-bs-toggle="modal"
                    data-bs-target="#pagamentoModal"
                    data-quota-pay="{{ $payData }}"
                    title="Registra pagamento">
                <i class="bi bi-cash-coin"></i>
            </button>
            @if ($hasOnlineGateway)
                <form method="POST" action="{{ route('quote.payment-link', $quota) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary" title="Genera link pagamento">
                        <i class="bi bi-link-45deg"></i>
                    </button>
                </form>
            @endif
        @endcan
    @else
        <a href="{{ route('quote.ricevuta', $quota) }}" class="btn btn-outline-secondary" title="Scarica ricevuta PDF">
            <i class="bi bi-file-earmark-pdf"></i>
        </a>
    @endif
    @can('update', $quota)
        <button type="button"
                class="btn btn-outline-secondary"
                data-bs-toggle="modal"
                data-bs-target="#quotaModal"
                data-quota-edit="{{ $editData }}"
                title="Modifica">
            <i class="bi bi-pencil"></i>
        </button>
    @endcan
    @can('delete', $quota)
        <form method="POST" action="{{ route('quote.destroy', $quota) }}" class="d-inline"
              onsubmit="return confirm('Eliminare definitivamente questa quota?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger" title="Elimina">
                <i class="bi bi-trash"></i>
            </button>
        </form>
    @endcan
</div>
