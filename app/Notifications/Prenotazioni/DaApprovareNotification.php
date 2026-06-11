<?php

namespace App\Notifications\Prenotazioni;

use App\Models\Prenotazione;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Notifica agli amministratori del tenant: una nuova prenotazione è in
 * attesa di approvazione (evento con richiede_approvazione). I posti sono
 * già riservati; l'esito va dato dal tab Prenotazioni dell'evento.
 *
 * database + mail per tenant (pattern EventoEsauritoNotification);
 * invio best-effort, mai dentro la transazione di dominio.
 */
class DaApprovareNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Prenotazione $prenotazione) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $evento = $this->prenotazione->evento()
            ->withoutGlobalScope('associazione')
            ->first();

        $titolo = $evento->titolo ?? 'Evento';
        $data = $evento?->data_evento?->format('d/m/Y H:i') ?? 'data da definire';
        $posti = (int) $this->prenotazione->posti;

        $message = (new MailMessage)
            ->subject('Prenotazione da approvare — '.$titolo)
            ->greeting('Ciao,')
            ->line('è arrivata la richiesta di prenotazione '.$this->prenotazione->codice
                ." ({$posti} ".($posti === 1 ? 'posto' : 'posti').') per l\'evento "'
                .$titolo.'" del '.$data.'.')
            ->line('L\'evento richiede l\'approvazione: i posti sono riservati '
                .'finché non approvi o rifiuti la richiesta.');

        if ($evento !== null && Route::has('eventi.prenotazioni.index')) {
            $message->action(
                'Gestisci le prenotazioni',
                route('eventi.prenotazioni.index', ['evento' => $evento->id])
            );
        }

        return TenantNotificationMailer::applica(
            $message->salutation('Il gestionale'),
            $this->prenotazione->associazione
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $evento = $this->prenotazione->evento()
            ->withoutGlobalScope('associazione')
            ->first();

        return [
            'tipo' => 'prenotazione_da_approvare',
            'prenotazione_id' => $this->prenotazione->id,
            'codice' => $this->prenotazione->codice,
            'evento_id' => $this->prenotazione->evento_id,
            'evento_titolo' => $evento->titolo ?? 'Evento',
            'posti' => (int) $this->prenotazione->posti,
        ];
    }
}
