<?php

namespace App\Notifications\Comunicazione;

use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Riepilogo giornaliero dei nuovi messaggi nelle discussioni seguite
 * (issue #13): una riga per discussione con il link per leggerla.
 */
class RiepilogoDiscussioniNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{titolo: string, spazio: ?string, nuovi: int, url: string}>  $discussioni
     */
    public function __construct(
        public array $discussioni,
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
        $associazione = $notifiable instanceof Socio ? $notifiable->associazione : null;

        $message = (new MailMessage)
            ->subject('Nuovi messaggi nelle discussioni che segui')
            ->greeting(trim('Ciao '.($notifiable->nome ?? '')))
            ->line('Ecco le novità nelle discussioni che segui:');

        foreach ($this->discussioni as $riga) {
            $nuovi = $riga['nuovi'] === 1 ? '1 nuovo messaggio' : "{$riga['nuovi']} nuovi messaggi";
            $message->line("• {$riga['titolo']}".($riga['spazio'] ? " ({$riga['spazio']})" : '').": {$nuovi} — {$riga['url']}");
        }

        $message->line('Puoi smettere di seguire una discussione dalla sua pagina.')
            ->salutation('A presto, '.($associazione->nome ?? 'la tua associazione'));

        return TenantNotificationMailer::applica($message, $associazione, $notifiable instanceof Socio ? $notifiable : null);
    }
}
