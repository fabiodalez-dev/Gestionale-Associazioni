<?php

namespace App\Notifications\EventiVita;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\FeedbackEvento;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Richiesta di feedback post-evento (f.11): inviata il giorno dopo
 * l'evento ai partecipanti con check-in. Il link al form pubblico è
 * FIRMATO (APP_KEY) e porta il token del feedback (capability token,
 * unico per prenotazione).
 *
 * Pattern PrenotazioneNotification: ShouldQueue, database + mail,
 * SMTP del tenant quando configurato.
 */
class FeedbackEventoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public FeedbackEvento $feedback) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $evento = $this->evento();
        $titolo = $evento->titolo ?? 'Evento';

        $message = (new MailMessage)
            ->subject('Com\'è andata? Raccontacelo — '.$titolo)
            ->greeting('Ciao '.($notifiable->nome ?? '').',')
            ->line('grazie per aver partecipato a "'.$titolo.'"'
                .($evento?->data_evento !== null ? ' del '.$evento->data_evento->format('d/m/Y') : '').'.')
            ->line('Ci piacerebbe sapere come ti sei trovato: bastano 30 secondi.')
            ->action('Lascia il tuo feedback', $this->urlForm())
            ->line('Il tuo voto (da 1 a 5) e il tuo commento ci aiutano a organizzare eventi sempre migliori.')
            ->salutation('Grazie!');

        return TenantNotificationMailer::applica($message, $this->associazione());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'feedback_evento',
            'feedback_id' => $this->feedback->id,
            'evento_id' => $this->feedback->evento_id,
            'evento_titolo' => $this->evento()->titolo ?? 'Evento',
            'prenotazione_id' => $this->feedback->prenotazione_id,
        ];
    }

    /**
     * URL pubblico FIRMATO del form di valutazione.
     */
    protected function urlForm(): string
    {
        return URL::signedRoute('feedback-eventi.show', [
            'token' => $this->feedback->token,
        ]);
    }

    protected function evento(): ?Evento
    {
        return $this->feedback->evento()
            ->withoutGlobalScope('associazione')
            ->first();
    }

    protected function associazione(): ?Associazione
    {
        return $this->feedback->associazione;
    }
}
