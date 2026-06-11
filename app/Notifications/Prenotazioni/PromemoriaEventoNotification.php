<?php

namespace App\Notifications\Prenotazioni;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Promemoria del giorno prima (scheduler prenotazioni:promemoria-eventi):
 * inviato alle prenotazioni confermate di eventi che iniziano tra 24 e
 * 48 ore, se la preferenza "promemoria eventi" dell'account è attiva.
 */
class PromemoriaEventoNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $evento = $this->evento();

        $message = $this->baseMail($notifiable, 'Promemoria: '.$this->titoloEvento().' è domani!')
            ->line('ti ricordiamo la tua prenotazione '.$this->prenotazione->codice
                .' per "'.$this->titoloEvento().'" del '.$this->dataEvento().'.')
            ->line('Posti prenotati: '.$this->prenotazione->posti.'.');

        if (filled($evento?->luogo)) {
            $message->line('Luogo: '.$evento->luogo.'.');
        }

        return $message
            ->action('Vedi il biglietto', $this->urlDettaglio())
            ->line('Presenta il QR code del biglietto all\'ingresso per il check-in.')
            ->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_promemoria_evento'] + $this->datiBase() + [
            'data_evento' => $this->evento()?->data_evento?->toIso8601String(),
        ];
    }
}
