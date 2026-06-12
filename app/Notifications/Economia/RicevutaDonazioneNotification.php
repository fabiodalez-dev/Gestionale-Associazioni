<?php

namespace App\Notifications\Economia;

use App\Models\Donazione;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ricevuta email della donazione online pagata (f.15 del piano
 * "Gestione 2"): inviata on-demand all'email del donatore con il
 * layout brandizzato del tenant (TenantNotificationMailer).
 */
class RicevutaDonazioneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Donazione $donazione) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->donazione->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la nostra associazione';
        $importo = number_format((float) $this->donazione->importo, 2, ',', '.');
        $data = ($this->donazione->pagata_at ?? $this->donazione->created_at)?->format('d/m/Y') ?? now()->format('d/m/Y');

        $message = (new MailMessage)
            ->subject("Grazie per la tua donazione a {$nomeAssociazione}")
            ->greeting('Ciao '.(filled($this->donazione->donatore_nome) ? $this->donazione->donatore_nome : '').',')
            ->line("grazie di cuore per la tua donazione di € {$importo} a favore di {$nomeAssociazione}.")
            ->line("Data: {$data}".(filled($this->donazione->transaction_id) ? " — Riferimento transazione: {$this->donazione->transaction_id}" : ''))
            ->line('Questa email vale come ricevuta del pagamento effettuato online.')
            ->line('Il tuo sostegno ci aiuta a portare avanti le nostre attività.')
            ->salutation('Grazie ancora!');

        return TenantNotificationMailer::applica($message, $associazione);
    }
}
