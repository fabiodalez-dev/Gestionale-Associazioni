<?php

namespace App\Notifications\Iscrizioni;

use App\Models\RichiestaIscrizione;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Richiesta di iscrizione socio rifiutata dall'admin (motivo obbligatorio,
 * riportato all'account). Il socio preiscritto è stato eliminato: l'account
 * può inviare una nuova richiesta dal form pubblico.
 *
 * Destinatario: l'Account del frontend pubblico. database + mail per tenant.
 */
class IscrizioneRifiutataNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RichiestaIscrizione $richiesta) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->richiesta->associazione;

        $message = (new MailMessage)
            ->subject('Richiesta di iscrizione non approvata — '.($associazione->nome ?? 'Associazione'))
            ->greeting('Ciao '.($notifiable->nome ?? '').',')
            ->line('la tua richiesta di iscrizione a "'
                .($associazione->nome ?? 'Associazione')
                .'" non è stata approvata.');

        if (filled($this->richiesta->motivo_rifiuto)) {
            $message->line('Motivo: '.$this->richiesta->motivo_rifiuto.'.');
        }

        return TenantNotificationMailer::applica(
            $message
                ->line('Puoi inviare una nuova richiesta dal sito oppure contattare direttamente l\'associazione.')
                ->salutation('A presto!'),
            $associazione
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'iscrizione_rifiutata',
            'richiesta_id' => $this->richiesta->id,
            'motivo_rifiuto' => $this->richiesta->motivo_rifiuto,
        ];
    }
}
