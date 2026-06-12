<?php

namespace App\Notifications\Quote;

use App\Models\Quota;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Esito pagamento quota (miglioramento 25): notifica unificata
 * database + email, con rispetto dell'opt-out email del socio.
 */
class PagamentoQuotaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Quota $quota) {}

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
        $importo = number_format((float) $this->quota->importo, 2, ',', '.');
        $dataPagamento = $this->quota->data_pagamento?->format('d/m/Y') ?? now()->format('d/m/Y');
        $associazione = $this->quota->associazione->nome ?? 'la tua associazione';

        $message = (new MailMessage)
            ->subject("Conferma pagamento quota {$this->quota->anno}")
            ->greeting('Ciao '.($notifiable instanceof Socio ? $notifiable->nome : ''))
            ->line("Abbiamo registrato il pagamento della tua quota \"{$this->quota->tipo}\" per l'anno {$this->quota->anno}.")
            ->line("Importo: € {$importo}")
            ->line("Data pagamento: {$dataPagamento}")
            ->line("Grazie da {$associazione}.")
            ->salutation('A presto!');

        return TenantNotificationMailer::applica(
            $message,
            $this->quota->associazione,
            $notifiable instanceof Socio ? $notifiable : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'pagamento_quota',
            'quota_id' => $this->quota->id,
            'anno' => $this->quota->anno,
            'importo' => (string) $this->quota->importo,
            'metodo_pagamento' => $this->quota->metodo_pagamento,
            'gateway' => $this->quota->gateway,
            'data_pagamento' => $this->quota->data_pagamento?->toDateString(),
        ];
    }
}
