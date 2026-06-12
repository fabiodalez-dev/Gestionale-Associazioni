<?php

namespace App\Notifications\Rinnovi;

use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Promemoria rinnovo tessera in scadenza (f.3 del piano Vita sociale):
 * notifica multi-step (-30/-15/-3 giorni configurabili per tenant),
 * canali database + mail con rispetto dell'opt-out email del socio.
 */
class PromemoriaRinnovoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Tessera $tessera,
        public int $step,
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
        $scadenza = $this->tessera->data_scadenza->format('d/m/Y');
        $associazione = $this->tessera->associazione->nome ?? 'la tua associazione';
        $giorni = max(0, (int) now()->startOfDay()->diffInDays($this->tessera->data_scadenza->startOfDay(), false));

        return (new MailMessage)
            ->subject("La tua tessera scade il {$scadenza} — rinnova ora")
            ->greeting('Ciao '.($notifiable instanceof Socio ? $notifiable->nome : ''))
            ->line("La tua tessera n. {$this->tessera->numero_tessera} di {$associazione} scade il {$scadenza}".($giorni > 0 ? " (tra {$giorni} giorni)." : '.'))
            ->line('Per non perdere i vantaggi del tesseramento, rinnova la tua iscrizione: puoi farlo online dalla tua area soci oppure contattando la segreteria.')
            ->line("Grazie da {$associazione}.")
            ->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'promemoria_rinnovo',
            'tessera_id' => $this->tessera->id,
            'numero_tessera' => $this->tessera->numero_tessera,
            'anno_validita' => $this->tessera->anno_validita,
            'data_scadenza' => $this->tessera->data_scadenza->toDateString(),
            'step' => $this->step,
        ];
    }
}
