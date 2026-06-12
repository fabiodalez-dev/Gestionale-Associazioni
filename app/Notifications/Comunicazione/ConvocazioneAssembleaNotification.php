<?php

namespace App\Notifications\Comunicazione;

use App\Models\Annuncio;
use App\Models\Evento;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convocazione di assemblea ai soci (addendum "Convocazioni assemblee"):
 * inviata IN CODA dall'admin (annunci.invia-convocazione) a tutti i soci
 * Attivi del tenant con email e senza opt-out. Canali database + mail con
 * layout brandizzato per tenant (TenantNotificationMailer): titolo,
 * data/luogo assemblea, ordine del giorno, link all'evento collegato
 * (se pubblicato) e link alla pagina Novità del mini-sito.
 */
class ConvocazioneAssembleaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Annuncio $annuncio,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof Socio && filled($notifiable->email) && ! $notifiable->email_opt_out) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->annuncio->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la tua associazione';
        $data = $this->annuncio->data_assemblea?->format('d/m/Y H:i') ?? 'da definire';

        $message = (new MailMessage)
            ->subject("Convocazione assemblea — {$this->annuncio->titolo}")
            ->greeting('Ciao '.($notifiable instanceof Socio ? $notifiable->nome : ''))
            ->line("Sei convocato/a all'assemblea \"{$this->annuncio->titolo}\" di {$nomeAssociazione}.")
            ->line("Data e ora: {$data}");

        if (filled($this->annuncio->luogo_assemblea)) {
            $message->line("Luogo: {$this->annuncio->luogo_assemblea}");
        }

        if (filled($this->annuncio->ordine_del_giorno)) {
            $message->line('Ordine del giorno:')
                ->line($this->annuncio->ordine_del_giorno);
        }

        $evento = $this->annuncio->evento;
        if ($evento instanceof Evento
            && $evento->stato_pubblicazione === Evento::STATO_PUBBLICATO
            && filled($associazione?->slug)) {
            $message->action(
                'Conferma la tua presenza',
                route('pubblico.eventi.show', ['associazione' => $associazione->slug, 'evento' => $evento->id])
            );
        }

        if (filled($associazione?->slug)) {
            $message->line('Tutti i dettagli sono pubblicati nella sezione Comunicazioni del nostro sito: '
                .route('pubblico.novita', $associazione->slug));
        }

        $message->salutation("A presto, {$nomeAssociazione}");

        return TenantNotificationMailer::applica(
            $message,
            $associazione,
            $notifiable instanceof Socio ? $notifiable : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'convocazione_assemblea',
            'annuncio_id' => $this->annuncio->id,
            'titolo' => $this->annuncio->titolo,
            'data_assemblea' => $this->annuncio->data_assemblea?->toIso8601String(),
            'luogo_assemblea' => $this->annuncio->luogo_assemblea,
            'evento_id' => $this->annuncio->evento_id,
        ];
    }
}
