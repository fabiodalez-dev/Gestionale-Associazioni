<?php

namespace App\Notifications\Prenotazioni;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Finestra di pagamento superata: la prenotazione è scaduta e i posti
 * sono stati liberati (eventuale promozione della lista d'attesa).
 */
class PagamentoScadutoNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMail($notifiable, 'Prenotazione scaduta — '.$this->titoloEvento())
            ->line('il pagamento della prenotazione '.$this->prenotazione->codice
                .' per "'.$this->titoloEvento().'" del '.$this->dataEvento()
                .' non è arrivato in tempo: la prenotazione è scaduta e i posti sono stati liberati.')
            ->line('Se sei ancora interessato puoi prenotare di nuovo dal sito (posti permettendo).')
            ->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_pagamento_scaduto'] + $this->datiBase();
    }
}
