<?php

namespace App\Notifications\Prenotazioni;

use App\Models\Account;
use App\Models\Prenotazione;
use App\Services\Email\TenantNotificationMailer;
use App\Services\Prenotazioni\BigliettoService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reinvio del biglietto di una prenotazione confermata (azione admin
 * "reinvia biglietto", best-effort: invio sincrono, eccezioni gestite
 * dal chiamante). Email per tenant (TenantNotificationMailer) con il
 * PDF del biglietto in allegato.
 */
class BigliettoPrenotazioneNotification extends Notification
{
    public function __construct(public Prenotazione $prenotazione) {}

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
        $associazione = $this->prenotazione->associazione;
        $evento = $this->prenotazione->evento()->withoutGlobalScope('associazione')->first();

        /** @var BigliettoService $biglietti */
        $biglietti = app(BigliettoService::class);

        $message = (new MailMessage)
            ->subject('Il tuo biglietto — '.($evento->titolo ?? 'Evento'))
            ->greeting('Ciao '.$notifiable->nome.',')
            ->line('in allegato trovi il biglietto della tua prenotazione '.$this->prenotazione->codice
                .' per "'.($evento->titolo ?? 'Evento').'"'
                .($evento?->data_evento ? ' del '.$evento->data_evento->format('d/m/Y H:i') : '').'.')
            ->line('Posti prenotati: '.$this->prenotazione->posti.'.')
            ->line('Presenta il QR code del biglietto all\'ingresso per il check-in.')
            ->salutation('A presto!')
            ->attachData(
                $biglietti->pdfBinary($this->prenotazione),
                $biglietti->pdfFilename($this->prenotazione),
                ['mime' => 'application/pdf']
            );

        return TenantNotificationMailer::applica($message, $associazione);
    }
}
