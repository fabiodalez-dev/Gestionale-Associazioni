<?php

namespace App\Notifications;

use App\Models\Scadenza;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Promemoria reale per scadenza in finestra promemoria (estensione §6.3 della spec):
 * inviato dal comando schedulato scadenze:invia-promemoria all'assegnatario
 * oppure agli admin del tenant se la scadenza non è assegnata.
 */
class PromemoriaScadenzaNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Scadenza $scadenza,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $giorni = $this->scadenza->giorni_residui;

        $quando = match (true) {
            $giorni < 0 => 'è scaduta da '.abs($giorni).' '.(abs($giorni) === 1 ? 'giorno' : 'giorni'),
            $giorni === 0 => 'scade oggi',
            default => 'scade tra '.$giorni.' '.($giorni === 1 ? 'giorno' : 'giorni'),
        };

        return (new MailMessage)
            ->subject('Promemoria scadenza: '.$this->scadenza->titolo)
            ->greeting('Ciao!')
            ->line("La scadenza \"{$this->scadenza->titolo}\" {$quando} ({$this->scadenza->data_scadenza->format('d/m/Y')}).")
            ->line('Tipo: '.$this->scadenza->tipo_scadenza.' — Priorità: '.$this->scadenza->priorita)
            ->lineIf(filled($this->scadenza->descrizione), (string) $this->scadenza->descrizione)
            ->action('Vai alle scadenze', route('scadenze.index'))
            ->salutation('— '.config('app.name'));
    }
}
