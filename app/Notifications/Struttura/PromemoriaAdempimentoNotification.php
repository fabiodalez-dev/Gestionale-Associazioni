<?php

namespace App\Notifications\Struttura;

use App\Models\Adempimento;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Promemoria mensile di un adempimento ricorrente in scadenza nel mese
 * corrente e non ancora completato per l'anno (f.20 del piano
 * "Organizzazione 3"): inviato IN CODA dal comando adempimenti:promemoria
 * agli admin del tenant. Layout brandizzato (TenantNotificationMailer).
 */
class PromemoriaAdempimentoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Adempimento $adempimento,
        public int $anno,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->adempimento->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la tua associazione';

        $mesi = [
            1 => 'gennaio', 2 => 'febbraio', 3 => 'marzo', 4 => 'aprile',
            5 => 'maggio', 6 => 'giugno', 7 => 'luglio', 8 => 'agosto',
            9 => 'settembre', 10 => 'ottobre', 11 => 'novembre', 12 => 'dicembre',
        ];
        $mese = $mesi[$this->adempimento->mese_scadenza] ?? '';

        $message = (new MailMessage)
            ->subject("Promemoria adempimento: {$this->adempimento->titolo}")
            ->greeting('Ciao!')
            ->line("L'adempimento \"{$this->adempimento->titolo}\" è in scadenza a {$mese} {$this->anno} e non risulta ancora completato.")
            ->lineIf(filled($this->adempimento->descrizione), (string) $this->adempimento->descrizione)
            ->action('Vai allo scadenzario', route('scadenzario.index'))
            ->salutation("— {$nomeAssociazione}");

        return TenantNotificationMailer::applica($message, $associazione);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'promemoria_adempimento',
            'adempimento_id' => $this->adempimento->id,
            'titolo' => $this->adempimento->titolo,
            'anno' => $this->anno,
            'mese_scadenza' => $this->adempimento->mese_scadenza,
        ];
    }
}
