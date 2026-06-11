<?php

namespace App\Notifications\Accounts;

use App\Models\Account;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Verifica dell'indirizzo email di un account del frontend pubblico.
 *
 * Link firmato (signed, TTL 60') verso pubblico.verifica-email.verifica
 * con lo slug del tenant; invio con l'SMTP del tenant se configurato,
 * altrimenti col mailer di default (TenantNotificationMailer).
 */
class VerificaEmailAccount extends Notification implements ShouldQueue
{
    use Queueable;

    /** Validità del link di verifica, in minuti. */
    public const SCADENZA_MINUTI = 60;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var Account $notifiable */
        $associazione = $notifiable->associazione;

        $message = (new MailMessage)
            ->subject('Verifica il tuo indirizzo email — '.($associazione->nome ?? 'Eventi'))
            ->greeting('Ciao '.$notifiable->nome.',')
            ->line('grazie per esserti registrato! Per completare la registrazione conferma il tuo indirizzo email.')
            ->action('Verifica email', $this->verificationUrl($notifiable))
            ->line('Il link è valido per '.self::SCADENZA_MINUTI.' minuti.')
            ->line('Se non ti sei registrato tu, ignora questa email.')
            ->salutation('A presto!');

        return TenantNotificationMailer::applica($message, $associazione);
    }

    /**
     * URL firmato di verifica, con lo slug del tenant nel percorso.
     */
    protected function verificationUrl(Account $account): string
    {
        return URL::temporarySignedRoute(
            'pubblico.verifica-email.verifica',
            now()->addMinutes(self::SCADENZA_MINUTI),
            [
                'associazione' => $account->associazione?->slug,
                'id' => $account->getKey(),
                'hash' => sha1((string) $account->getEmailForVerification()),
            ]
        );
    }
}
