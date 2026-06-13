<?php

namespace App\Notifications\VolontariTurni;

use App\Models\Socio;
use App\Models\Turno;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Promemoria turno (f.6 del piano "Organizzazione 3"): inviato il giorno
 * prima ai volontari confermati dal comando turni:promemoria. Layout email
 * brandizzato per tenant + SMTP del tenant quando configurato.
 */
class PromemoriaTurnoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Turno $turno) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $orario = substr((string) $this->turno->ora_inizio, 0, 5).'–'.substr((string) $this->turno->ora_fine, 0, 5);

        $message = (new MailMessage)
            ->subject('Promemoria turno: '.$this->turno->titolo)
            ->greeting('Ciao '.($notifiable->nome ?? '').',')
            ->line('ti ricordiamo il turno di volontariato "'.$this->turno->titolo.'" di domani '
                .$this->turno->data->format('d/m/Y').' dalle '.$orario.'.')
            ->lineIf($this->turno->spazio !== null, 'Luogo: '.(string) $this->turno->spazio?->nome)
            ->lineIf(filled($this->turno->note), (string) $this->turno->note)
            ->line('Grazie per il tuo contributo!')
            ->salutation('— '.config('app.name'));

        return TenantNotificationMailer::applica(
            $message,
            $this->turno->associazione()->withoutGlobalScopes()->first(),
            $notifiable instanceof Socio ? $notifiable : null
        );
    }
}
