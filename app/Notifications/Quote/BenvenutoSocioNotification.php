<?php

namespace App\Notifications\Quote;

use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Benvenuto al socio attivato automaticamente dopo pagamento online
 * (parità con il template email `benvenuto` del legacy, via notifiche Laravel).
 */
class BenvenutoSocioNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Socio $socio) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof Socio && filled($notifiable->email) && ! $notifiable->email_opt_out) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->socio->associazione->nome ?? 'la tua associazione';

        $message = (new MailMessage)
            ->subject("Benvenuto in {$associazione}!")
            ->greeting("Ciao {$this->socio->nome},")
            ->line("la tua iscrizione a {$associazione} è stata attivata: il pagamento della quota è andato a buon fine.")
            ->line('La tua tessera è stata generata e sarà disponibile nella tua area soci.')
            ->salutation('Benvenuto a bordo!');

        return TenantNotificationMailer::applica($message, $this->socio->associazione, $this->socio);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'benvenuto',
            'socio_id' => $this->socio->id,
            'stato' => $this->socio->stato,
        ];
    }
}
