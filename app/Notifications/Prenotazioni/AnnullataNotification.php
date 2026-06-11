<?php

namespace App\Notifications\Prenotazioni;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Prenotazione annullata (dall'utente o dall'amministrazione):
 * conferma con il motivo registrato.
 */
class AnnullataNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->baseMail($notifiable, 'Prenotazione annullata — '.$this->titoloEvento())
            ->line('la prenotazione '.$this->prenotazione->codice
                .' per "'.$this->titoloEvento().'" del '.$this->dataEvento()
                .' è stata annullata.');

        if (filled($this->prenotazione->motivo_annullamento)) {
            $message->line('Motivo: '.$this->prenotazione->motivo_annullamento.'.');
        }

        if ((float) $this->prenotazione->importo_totale > 0 && $this->prenotazione->pagata_at !== null) {
            $message->line('Per l\'eventuale rimborso verrai contattato dall\'associazione.');
        }

        return $message
            ->line('Puoi sempre prenotare di nuovo dal sito, posti permettendo.')
            ->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_annullata'] + $this->datiBase() + [
            'annullata_at' => $this->prenotazione->annullata_at?->toIso8601String(),
            'motivo_annullamento' => $this->prenotazione->motivo_annullamento,
        ];
    }
}
