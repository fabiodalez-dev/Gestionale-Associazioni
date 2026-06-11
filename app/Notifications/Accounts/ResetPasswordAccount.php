<?php

namespace App\Notifications\Accounts;

use App\Models\Account;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reset password per gli account del frontend pubblico (broker 'accounts').
 *
 * Link verso pubblico.password.reset del tenant; invio con l'SMTP del
 * tenant se configurato, altrimenti col mailer di default.
 */
class ResetPasswordAccount extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

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

        $url = route('pubblico.password.reset', [
            'associazione' => $associazione?->slug,
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $scadenza = (int) config('auth.passwords.accounts.expire', 60);

        $message = (new MailMessage)
            ->subject('Reimposta la tua password — '.($associazione->nome ?? 'Eventi'))
            ->greeting('Ciao '.$notifiable->nome.',')
            ->line('abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account.')
            ->action('Reimposta password', $url)
            ->line("Il link è valido per {$scadenza} minuti.")
            ->line('Se non hai richiesto tu la reimpostazione, ignora questa email: la tua password resterà invariata.')
            ->salutation('A presto!');

        return TenantNotificationMailer::applica($message, $associazione);
    }
}
