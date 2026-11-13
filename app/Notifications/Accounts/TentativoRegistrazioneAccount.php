<?php

namespace App\Notifications\Accounts;

use App\Models\Account;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avviso al titolare di un account del frontend pubblico: qualcuno ha
 * provato a registrarsi di nuovo con la sua email. Nessun dato scritto
 * nel modulo finisce nel messaggio; il link porta al recupero password.
 */
class TentativoRegistrazioneAccount extends Notification implements ShouldQueue
{
    use Queueable;

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
        $slug = $associazione?->slug;

        $message = (new MailMessage)
            ->subject('Tentativo di registrazione con la tua email — '.($associazione->nome ?? 'Eventi'))
            ->greeting('Ciao '.$notifiable->nome.',')
            ->line('qualcuno ha appena provato a registrarsi sul sito di '.($associazione->nome ?? 'questa associazione').' con il tuo indirizzo email, che ha già un account: non ne è stato creato un altro.')
            ->line('Se sei stato tu, accedi con la tua password oppure, se non la ricordi, impostane una nuova.')
            ->action('Recupera la password', $slug !== null ? route('pubblico.password.richiesta', $slug) : url('/'))
            ->line('Se non sei stato tu, ignora questa email: il tuo account non è stato modificato.')
            ->salutation('A presto!');

        return TenantNotificationMailer::applica($message, $associazione);
    }
}
