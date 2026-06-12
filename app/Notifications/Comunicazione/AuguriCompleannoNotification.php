<?php

namespace App\Notifications\Comunicazione;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Auguri di compleanno al socio (f.9 piano Gestione 2): inviata dal
 * comando compleanni:auguri ai soci attivi nel giorno del compleanno,
 * solo per i tenant con auguri_automatici attivo. Canali database + mail
 * (layout brandizzato del round 1 via TenantNotificationMailer),
 * rispettando l'opt-out email del socio.
 */
class AuguriCompleannoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Associazione $associazione,
    ) {}

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
        $nome = $notifiable instanceof Socio ? $notifiable->nome : '';
        $associazione = $this->associazione->nome;

        return (new MailMessage)
            ->subject("Tanti auguri, {$nome}! 🎂")
            ->greeting('Ciao '.$nome)
            ->line("Tutta {$associazione} ti augura un felicissimo compleanno!")
            ->line('Grazie di far parte della nostra associazione: ti auguriamo una giornata speciale.')
            ->salutation("Con affetto, {$associazione}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'auguri_compleanno',
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->format('Y'),
        ];
    }
}
