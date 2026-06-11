<?php

namespace App\Services\Prenotazioni\Contracts;

use App\Models\Prenotazione;

/**
 * Contratto del post-pagamento prenotazione: il webhook (PaymentService)
 * dispatcha qui quando metadata.tipo = 'prenotazione' (Stripe) o il
 * custom_id è prefissato "prenotazione:" (PayPal).
 *
 * Implementato da App\Services\Prenotazioni\PrenotazioneService: conferma
 * la prenotazione sotto lock per-evento, idempotente sul replay (già
 * pagata → ack senza effetti), riconferma una prenotazione già scaduta
 * anche in overbooking (visibile in admin).
 */
interface GestiscePagamentoPrenotazione
{
    /**
     * Pagamento andato a buon fine sul gateway.
     *
     * @param  string  $prenotazioneId  UUID della prenotazione
     * @param  string  $associazioneId  UUID del tenant (già validato dal webhook)
     * @param  string  $gateway  'stripe' | 'paypal'
     * @param  string  $transactionId  id transazione del gateway
     * @return Prenotazione|null la prenotazione aggiornata; null solo se
     *                           inesistente per quel tenant (il chiamante
     *                           ack-a comunque il webhook)
     */
    public function onPagamentoCompletato(
        string $prenotazioneId,
        string $associazioneId,
        string $gateway,
        string $transactionId,
    ): ?Prenotazione;
}
