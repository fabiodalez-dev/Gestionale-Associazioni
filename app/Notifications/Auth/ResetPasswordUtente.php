<?php

namespace App\Notifications\Auth;

use App\Models\Utente;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reset password degli utenti backend (broker 'utenti', Fortify).
 *
 * Invio sincrono: chi ha perso la password non deve dipendere dal worker delle code.
 * Usa l'SMTP dell'associazione dell'utente se configurato, altrimenti quello di sistema.
 */
class ResetPasswordUtente extends Notification
{
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
        /** @var Utente $notifiable */
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $scadenza = (int) config('auth.passwords.utenti.expire', 60);

        $message = (new MailMessage)
            ->subject('Reimposta la tua password — '.config('app.name'))
            ->greeting('Ciao '.$notifiable->nome.',')
            ->line('abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account amministratore.')
            ->action('Reimposta password', $url)
            ->line("Il link è valido per {$scadenza} minuti e può essere usato una sola volta.")
            ->line('Se non hai richiesto tu la reimpostazione, ignora questa email: la tua password resterà invariata.')
            ->salutation('A presto!');

        return TenantNotificationMailer::applica($message, $notifiable->associazione);
    }
}
