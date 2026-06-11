<?php

namespace App\Notifications\Prenotazioni;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Prenotazione in attesa di approvazione (evento con richiede_approvazione):
 * i posti sono riservati, l'esito arriva dopo la revisione dell'admin.
 * Per gli eventi a pagamento il link di pagamento parte SOLO dopo
 * l'approvazione (con la finestra dei 30 minuti).
 */
class InApprovazioneNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $posti = (int) $this->prenotazione->posti;

        $message = $this->baseMail($notifiable, 'Richiesta di prenotazione ricevuta — '.$this->titoloEvento())
            ->line('abbiamo ricevuto la tua richiesta di prenotazione '
                .$this->prenotazione->codice.' per "'.$this->titoloEvento()
                .'" del '.$this->dataEvento()
                ." ({$posti} ".($posti === 1 ? 'posto' : 'posti').').')
            ->line('Questo evento richiede l\'approvazione dell\'associazione: '
                .'i tuoi posti sono riservati e riceverai un\'email con l\'esito.');

        if ((float) $this->prenotazione->importo_totale > 0) {
            $message->line('Se la richiesta verrà approvata ti invieremo il link '
                .'per completare il pagamento.');
        }

        return $message
            ->action('Vedi la tua prenotazione', $this->urlDettaglio())
            ->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_in_approvazione'] + $this->datiBase();
    }
}
