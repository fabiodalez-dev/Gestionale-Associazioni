<?php

namespace App\Notifications\Iscrizioni;

use App\Models\Associazione;
use App\Services\Email\TenantNotificationMailer;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link firmato per confermare l'email di chi chiede l'iscrizione da ospite
 * (senza account) dal modulo pubblico /a/{slug}/iscriviti. Solo dopo il
 * clic la richiesta arriva all'associazione.
 */
class ConfermaEmailIscrizioneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Associazione $associazione,
        public string $url,
        public string $nome,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $nomeAssociazione = $this->associazione->nome ?? 'Associazione';

        $message = (new MailMessage)
            // Oggetto fisso: nessun testo scritto nel modulo pubblico
            ->subject('Conferma la tua richiesta di iscrizione — '.$nomeAssociazione)
            ->greeting('Ciao'.($this->nome !== '' ? ' '.$this->nome : '').',')
            ->line('per inviare la tua richiesta di iscrizione a "'.$nomeAssociazione.'" conferma che questo indirizzo email è tuo.')
            ->action('Conferma la richiesta', $this->url)
            ->line('Il link vale '.FormIscrizioneService::ORE_VALIDITA_CONFERMA.' ore: senza conferma la richiesta non viene inviata.')
            ->line('Se non hai chiesto tu l\'iscrizione, ignora questa email: non succede nulla.')
            ->salutation('A presto!');

        return TenantNotificationMailer::applica($message, $this->associazione);
    }
}
