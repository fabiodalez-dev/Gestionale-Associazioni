<?php

namespace App\Notifications\Prenotazioni;

use App\Models\Evento;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica agli amministratori del tenant: un evento ha raggiunto la
 * capienza (tutti i posti consumati da prenotazioni confermate o in
 * attesa di pagamento). Da qui in poi le nuove prenotazioni finiscono
 * in lista d'attesa (se attiva).
 *
 * database + mail per tenant (pattern PagamentoQuotaNotification);
 * invio best-effort, mai dentro la transazione di dominio.
 */
class EventoEsauritoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Evento $evento) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->evento->associazione;
        $data = $this->evento->data_evento->format('d/m/Y H:i');

        $message = (new MailMessage)
            ->subject('Evento al completo — '.$this->evento->titolo)
            ->greeting('Ciao,')
            ->line('l\'evento "'.$this->evento->titolo.'" del '.$data
                .' ha raggiunto la capienza di '.$this->evento->capienza.' posti.')
            ->line($this->evento->lista_attesa_attiva
                ? 'Le nuove prenotazioni entrano in lista d\'attesa: alla liberazione di posti la coda viene promossa automaticamente.'
                : 'La lista d\'attesa è disattivata: le nuove prenotazioni vengono rifiutate.')
            ->line('Puoi gestire le prenotazioni dalla scheda dell\'evento nel gestionale.')
            ->salutation('Il gestionale');

        return TenantNotificationMailer::applica($message, $associazione);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'evento_esaurito',
            'evento_id' => $this->evento->id,
            'evento_titolo' => $this->evento->titolo,
            'data_evento' => $this->evento->data_evento->toIso8601String(),
            'capienza' => (int) $this->evento->capienza,
            'lista_attesa_attiva' => (bool) $this->evento->lista_attesa_attiva,
        ];
    }
}
