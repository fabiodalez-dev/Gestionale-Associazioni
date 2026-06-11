<?php

namespace App\Notifications\Accounts;

use App\Models\Account;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Benvenuto dopo la verifica dell'indirizzo email: l'account è operativo
 * e può prenotare gli eventi dell'associazione.
 *
 * database + mail per tenant (pattern PagamentoQuotaNotification);
 * invio best-effort dal controller di verifica (mai bloccante).
 */
class BenvenutoAccount extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var Account $notifiable */
        $associazione = $notifiable->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la nostra associazione';

        $message = (new MailMessage)
            ->subject('Benvenuto! — '.$nomeAssociazione)
            ->greeting('Ciao '.$notifiable->nome.',')
            ->line("benvenuto su {$nomeAssociazione}! Il tuo indirizzo email è verificato e il tuo account è attivo.")
            ->line('Da ora puoi prenotare gli eventi e gestire tutto dalla tua area riservata: prenotazioni, biglietti, profilo e preferenze.')
            ->action('Scopri gli eventi', route('pubblico.eventi.index', [
                'associazione' => $associazione?->slug,
            ]))
            ->salutation('A presto!');

        return TenantNotificationMailer::applica($message, $associazione);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        /** @var Account $notifiable */
        return [
            'tipo' => 'benvenuto_account',
            'account_id' => $notifiable->id,
            'email_verified_at' => $notifiable->email_verified_at?->toIso8601String(),
        ];
    }
}
