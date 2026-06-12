<?php

namespace App\Notifications\Economia;

use App\Models\Quota;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sollecito di pagamento quota (f.16 del piano "Gestione 2"): email
 * brandizzata al socio con il link di pagamento online (token monouso).
 * Canali database + mail nel rispetto dell'opt-out email del socio.
 */
class SollecitoQuotaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Quota $quota,
        public ?string $paymentUrl = null,
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
        $associazione = $this->quota->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la tua associazione';
        $importo = number_format((float) $this->quota->importo, 2, ',', '.');
        $scadenza = $this->quota->data_scadenza->format('d/m/Y');

        $message = (new MailMessage)
            ->subject("Promemoria: quota {$this->quota->anno} da pagare — {$nomeAssociazione}")
            ->greeting('Ciao '.($notifiable instanceof Socio ? $notifiable->nome : '').',')
            ->line("ti ricordiamo che la tua quota associativa {$this->quota->anno} di € {$importo} risulta ancora da pagare (scadenza: {$scadenza}).");

        if ($this->paymentUrl !== null) {
            $message->action('Paga ora online', $this->paymentUrl)
                ->line('Il link di pagamento è personale e ha una validità limitata.');
        } else {
            $message->line('Puoi regolarizzare la quota contattando la segreteria o dalla tua area soci.');
        }

        return TenantNotificationMailer::applica(
            $message->line("Se hai già provveduto al pagamento, ignora questa email. Grazie da {$nomeAssociazione}!")
                ->salutation('A presto!'),
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
            'tipo' => 'sollecito_quota',
            'quota_id' => $this->quota->id,
            'anno' => $this->quota->anno,
            'importo' => (string) $this->quota->importo,
            'data_scadenza' => $this->quota->data_scadenza->toDateString(),
        ];
    }
}
