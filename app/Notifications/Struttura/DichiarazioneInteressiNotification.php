<?php

namespace App\Notifications\Struttura;

use App\Models\Associazione;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Richiesta (e promemoria mensile) della dichiarazione annuale sui conflitti
 * di interesse a chi ricopre una carica (issue #11), con il link all'area soci.
 */
class DichiarazioneInteressiNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Associazione $associazione,
        public int $anno,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Dichiarazione sui conflitti di interesse {$this->anno}")
            ->greeting(trim('Ciao '.($notifiable->nome ?? '')))
            ->line("Come titolare di una carica di {$this->associazione->nome} ti chiediamo la dichiarazione annuale sui conflitti di interesse per il {$this->anno}.")
            ->line('Bastano due minuti: indica eventuali ruoli in altre organizzazioni, attività o rapporti economici con l\'associazione, oppure conferma di non averne.')
            ->action('Compila la dichiarazione', route('area-soci.dichiarazione-interessi'))
            ->salutation("Grazie, {$this->associazione->nome}");

        return TenantNotificationMailer::applica($message, $this->associazione, $notifiable instanceof Socio ? $notifiable : null);
    }
}
