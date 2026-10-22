{{-- Azioni contestuali per stato (spec soci §2.6) --}}
@if ($socio->stato === \App\Models\Socio::STATO_IN_ATTESA)
    <form method="POST" action="{{ route('soci.approva', $socio) }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-sm btn-success" title="Approva pre-iscrizione">
            <i class="bi bi-check-lg"></i> Approva
        </button>
    </form>
    <form method="POST" action="{{ route('soci.rifiuta', $socio) }}" class="d-inline"
          onsubmit="return confirm('Rifiutare ed eliminare definitivamente questa pre-iscrizione?');">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-danger" title="Rifiuta">
            <i class="bi bi-x-lg"></i> Rifiuta
        </button>
    </form>
@elseif ($socio->stato === \App\Models\Socio::STATO_IN_ATTESA_DI_PAGAMENTO)
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
            data-bs-target="#confermaPagamentoModal"
            data-socio-id="{{ $socio->id }}"
            data-socio-nome="{{ $socio->nome_completo }}"
            data-importo="{{ $socio->tipoSocio?->costo_tessera ?? $socio->associazione?->costo_tessera ?? '0' }}"
            data-action="{{ route('soci.conferma-pagamento', $socio) }}">
        <i class="bi bi-cash-coin"></i> Conferma pagamento
    </button>
    <form method="POST" action="{{ route('soci.rifiuta', $socio) }}" class="d-inline"
          onsubmit="return confirm('Rifiutare ed eliminare definitivamente questa pre-iscrizione?');">
        @csrf
        <button type="submit" class="btn btn-sm btn-icon btn-ghost btn-ghost-danger" title="Rifiuta" aria-label="Rifiuta">
            <i class="bi bi-x-lg"></i>
        </button>
    </form>
@else
    <a href="{{ route('soci.edit', $socio) }}" class="btn btn-sm btn-icon btn-ghost" title="Modifica" aria-label="Modifica">
        <i class="bi bi-pencil"></i>
    </a>
    <form method="POST" action="{{ route('soci.destroy', $socio) }}" class="d-inline"
          onsubmit="return confirm('Spostare il socio nel cestino?');">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-icon btn-ghost btn-ghost-danger" title="Elimina" aria-label="Elimina">
            <i class="bi bi-trash"></i>
        </button>
    </form>
@endif
