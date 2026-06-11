{{-- Badge di stato di una prenotazione --}}
@php /** @var \App\Models\Prenotazione $prenotazione */ @endphp
@switch($prenotazione->stato)
    @case(\App\Models\Prenotazione::STATO_CONFERMATA)
        <span class="badge text-bg-success">Confermata</span>
        @break
    @case(\App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO)
        <span class="badge text-bg-warning">In attesa di pagamento</span>
        @break
    @case(\App\Models\Prenotazione::STATO_IN_APPROVAZIONE)
        <span class="badge text-bg-primary">In attesa di approvazione</span>
        @break
    @case(\App\Models\Prenotazione::STATO_LISTA_ATTESA)
        <span class="badge text-bg-info">Lista d'attesa</span>
        @break
    @case(\App\Models\Prenotazione::STATO_SCADUTA)
        <span class="badge text-bg-secondary">Scaduta</span>
        @break
    @default
        <span class="badge text-bg-secondary">Annullata</span>
@endswitch
