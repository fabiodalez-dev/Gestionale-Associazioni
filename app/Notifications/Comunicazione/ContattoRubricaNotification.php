<?php

namespace App\Notifications\Comunicazione;

use App\Models\RubricaContatto;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Messaggio di un socio inviato tramite la rubrica interna (issue #14): il
 * destinatario risponde direttamente al mittente ("rispondi a"), il mittente
 * non conosce l'indirizzo del destinatario finché questi non risponde.
 */
class ContattoRubricaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public RubricaContatto $contatto,
        public Socio $mittente,
        public string $testo,
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
        $associazione = $this->mittente->associazione;

        $message = (new MailMessage)
            ->subject("{$this->contatto->oggetto} — da {$this->mittente->nome_completo}")
            ->replyTo((string) $this->mittente->email, $this->mittente->nome_completo)
            ->greeting(trim('Ciao '.($notifiable->nome ?? '')))
            ->line("{$this->mittente->nome_completo}, socio/a di {$associazione->nome}, ti ha scritto tramite la rubrica dei soci:")
            ->line($this->testo)
            ->line('Per rispondere basta rispondere a questa email: la risposta arriva direttamente a chi ti ha scritto, che fino ad allora non conosce il tuo indirizzo.')
            ->action('Segnala un uso improprio', route('area-soci.rubrica.segnala', $this->contatto))
            ->salutation($associazione->nome);

        return TenantNotificationMailer::applica($message, $associazione, $notifiable instanceof Socio ? $notifiable : null);
    }
}
