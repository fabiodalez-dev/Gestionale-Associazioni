<?php

namespace App\Notifications\Struttura;

use App\Models\Elezione;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Agli admin dell'associazione: un'elezione è stata proclamata da chi non
 * gestisce le cariche (permesso riunioni.update senza CaricaPolicy::create).
 * Gli eletti sono registrati; la sostituzione delle cariche nel registro
 * aspetta la conferma di un admin dalla pagina dell'elezione.
 */
class CaricheElezioneDaConfermareNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Elezione $elezione) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Elezione proclamata: cariche da confermare')
            ->greeting('Ciao,')
            ->line("È stata proclamata l'elezione {$this->elezione->organoLabel()} dell'assemblea del {$this->elezione->riunione?->data_ora?->format('d/m/Y')}.")
            ->line('Gli eletti sono registrati, ma la chiusura delle cariche uscenti e la creazione di quelle nuove nel registro degli organi sociali aspetta la conferma di un amministratore.')
            ->action('Conferma le cariche', route('elezioni.show', $this->elezione));

        return TenantNotificationMailer::applica($message->salutation('Il gestionale'), $this->elezione->associazione);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'cariche_elezione_da_confermare',
            'elezione_id' => $this->elezione->id,
            'riunione_id' => $this->elezione->riunione_id,
            'organo' => $this->elezione->organo,
        ];
    }
}
