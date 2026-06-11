<?php

namespace App\Services\Prenotazioni;

use App\Models\Prenotazione;
use App\Services\Prenotazioni\Contracts\GestiscePagamentoPrenotazione;
use Illuminate\Support\Facades\Log;

/**
 * Fallback no-op usato solo se PrenotazioneService non è disponibile nel
 * codebase (o non implementa ancora il contratto): ack-a il webhook (per
 * non far ritentare il gateway all'infinito) e logga l'evento per la
 * riconciliazione manuale.
 *
 * TODO(modulo prenotazioni): viene sostituito automaticamente dal binding
 * in PaymentServiceProvider non appena App\Services\Prenotazioni\PrenotazioneService
 * implementa GestiscePagamentoPrenotazione.
 */
class PagamentoPrenotazioneNoOp implements GestiscePagamentoPrenotazione
{
    public function onPagamentoCompletato(
        string $prenotazioneId,
        string $associazioneId,
        string $gateway,
        string $transactionId,
    ): ?Prenotazione {
        Log::warning('PagamentoPrenotazioneNoOp: pagamento prenotazione ricevuto ma PrenotazioneService non è disponibile (riconciliare manualmente)', [
            'prenotazione_id' => $prenotazioneId,
            'associazione_id' => $associazioneId,
            'gateway' => $gateway,
            'transaction_id' => $transactionId,
        ]);

        return null;
    }
}
