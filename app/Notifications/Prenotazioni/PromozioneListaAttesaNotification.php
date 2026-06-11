<?php

namespace App\Notifications\Prenotazioni;

use App\Models\Prenotazione;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Promozione dalla lista d'attesa (si sono liberati posti):
 * - evento gratuito → prenotazione già confermata;
 * - evento a pagamento → in attesa di pagamento con finestra dedicata
 *   di 24h e link alla pagina di pagamento.
 */
class PromozioneListaAttesaNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->baseMail($notifiable, 'Posti liberati: sei stato promosso! — '.$this->titoloEvento())
            ->line('buone notizie: si sono liberati posti per "'.$this->titoloEvento()
                .'" del '.$this->dataEvento().' e la tua prenotazione '
                .$this->prenotazione->codice.' è stata promossa dalla lista d\'attesa.');

        if ($this->prenotazione->stato === Prenotazione::STATO_IN_ATTESA_PAGAMENTO) {
            $importo = number_format((float) $this->prenotazione->importo_totale, 2, ',', '.');
            $scadenza = $this->prenotazione->scadenza_pagamento_at?->format('d/m/Y H:i');

            $message
                ->line("Per confermare i posti completa il pagamento di € {$importo}"
                    .($scadenza !== null ? " entro il {$scadenza} (hai 24 ore)" : '').'.')
                ->action('Paga ora', $this->urlPagamento())
                ->line('Se non paghi entro la scadenza i posti verranno liberati per il prossimo in coda.');
        } else {
            $message
                ->line('La prenotazione è confermata: non devi fare altro.')
                ->action('Vedi la prenotazione', $this->urlDettaglio())
                ->line('Presenta il QR code del biglietto all\'ingresso per il check-in.');
        }

        return $message->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_promossa'] + $this->datiBase() + [
            'promossa_at' => $this->prenotazione->promossa_at?->toIso8601String(),
            'scadenza_pagamento_at' => $this->prenotazione->scadenza_pagamento_at?->toIso8601String(),
        ];
    }
}
