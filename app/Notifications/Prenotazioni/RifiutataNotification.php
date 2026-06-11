<?php

namespace App\Notifications\Prenotazioni;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Richiesta di prenotazione rifiutata dall'amministrazione (evento con
 * richiede_approvazione): la prenotazione passa ad annullata, il motivo
 * inserito dall'admin viene riportato all'account e i posti rilasciati
 * promuovono la lista d'attesa.
 */
class RifiutataNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->baseMail($notifiable, 'Richiesta di prenotazione non approvata — '.$this->titoloEvento())
            ->line('la tua richiesta di prenotazione '.$this->prenotazione->codice
                .' per "'.$this->titoloEvento().'" del '.$this->dataEvento()
                .' non è stata approvata dall\'associazione.');

        if (filled($this->prenotazione->motivo_rifiuto)) {
            $message->line('Motivo: '.$this->prenotazione->motivo_rifiuto.'.');
        }

        return $message
            ->line('Per qualsiasi chiarimento puoi contattare direttamente l\'associazione.')
            ->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_rifiutata'] + $this->datiBase() + [
            'motivo_rifiuto' => $this->prenotazione->motivo_rifiuto,
        ];
    }
}
