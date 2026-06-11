<?php

namespace App\Notifications\Prenotazioni;

use App\Models\Prenotazione;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Evento al completo: prenotazione inserita in lista d'attesa, con la
 * posizione in coda (FIFO stretto, stesso ordinamento della promozione).
 */
class InListaAttesaNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMail($notifiable, 'Sei in lista d\'attesa — '.$this->titoloEvento())
            ->line('"'.$this->titoloEvento().'" del '.$this->dataEvento()
                .' è al completo: la tua prenotazione '.$this->prenotazione->codice
                .' è in lista d\'attesa.')
            ->line('Posizione in coda: '.$this->posizioneInCoda().'.')
            ->line('Se si liberano posti verrai promosso automaticamente e riceverai una email.')
            ->action('Vedi la prenotazione', $this->urlDettaglio())
            ->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_lista_attesa'] + $this->datiBase() + [
            'posizione_coda' => $this->posizioneInCoda(),
        ];
    }

    /**
     * Posizione 1-based nella coda: stesso ordinamento FIFO della
     * promozione (created_at, poi id come tie-break).
     */
    protected function posizioneInCoda(): int
    {
        return Prenotazione::query()
            ->withoutGlobalScope('associazione')
            ->where('evento_id', $this->prenotazione->evento_id)
            ->where('stato', Prenotazione::STATO_LISTA_ATTESA)
            ->where(function ($query): void {
                $query->where('created_at', '<', $this->prenotazione->created_at)
                    ->orWhere(function ($query): void {
                        $query->where('created_at', $this->prenotazione->created_at)
                            ->where('id', '<', $this->prenotazione->id);
                    });
            })
            ->count() + 1;
    }
}
