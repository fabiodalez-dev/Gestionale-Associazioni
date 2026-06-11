<?php

namespace App\Notifications\Prenotazioni;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base delle notifiche del ciclo di vita di una prenotazione
 * (destinatario: l'Account che ha prenotato).
 *
 * Pattern PagamentoQuotaNotification: ShouldQueue, canali database + mail,
 * email instradata sull'SMTP del tenant quando configurato
 * (TenantNotificationMailer) con fallback al mailer di default.
 *
 * Le relazioni (evento, associazione) vengono lette senza global scope
 * tenant: la notifica può essere smaltita da un worker fuori dal contesto
 * del frontend pubblico.
 */
abstract class PrenotazioneNotification extends Notification implements ShouldQueue
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

    protected function evento(): ?Evento
    {
        return $this->prenotazione->evento()
            ->withoutGlobalScope('associazione')
            ->first();
    }

    protected function associazione(): ?Associazione
    {
        return $this->prenotazione->associazione;
    }

    protected function titoloEvento(): string
    {
        return $this->evento()->titolo ?? 'Evento';
    }

    protected function dataEvento(): string
    {
        $data = $this->evento()?->data_evento;

        return $data !== null ? $data->format('d/m/Y H:i') : 'data da definire';
    }

    /**
     * MailMessage con subject/greeting comuni, già instradata sul mailer
     * del tenant (se configurato).
     */
    protected function baseMail(object $notifiable, string $subject): MailMessage
    {
        $nome = $notifiable->nome ?? '';

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Ciao '.$nome.',');

        return TenantNotificationMailer::applica($message, $this->associazione());
    }

    /**
     * Payload comune del canale database (il tipo specifico lo aggiunge
     * la sottoclasse).
     *
     * @return array<string, mixed>
     */
    protected function datiBase(): array
    {
        return [
            'prenotazione_id' => $this->prenotazione->id,
            'codice' => $this->prenotazione->codice,
            'evento_id' => $this->prenotazione->evento_id,
            'evento_titolo' => $this->titoloEvento(),
            'stato' => $this->prenotazione->stato,
            'posti' => (int) $this->prenotazione->posti,
        ];
    }

    /**
     * URL del dettaglio prenotazione nell'area riservata del tenant.
     */
    protected function urlDettaglio(): string
    {
        return route('pubblico.account.prenotazioni.show', [
            'associazione' => $this->associazione()?->slug,
            'prenotazione' => $this->prenotazione->id,
        ]);
    }

    /**
     * URL della pagina di pagamento (scelta gateway, countdown).
     */
    protected function urlPagamento(): string
    {
        return route('pubblico.prenotazioni.paga', [
            'associazione' => $this->associazione()?->slug,
            'prenotazione' => $this->prenotazione->id,
        ]);
    }
}
