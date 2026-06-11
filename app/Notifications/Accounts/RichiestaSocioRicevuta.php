<?php

namespace App\Notifications\Accounts;

use App\Models\Account;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica agli amministratori dell'associazione: un account del frontend
 * pubblico ha inviato la richiesta "diventa socio" (con motivazione).
 *
 * database + mail per tenant (pattern PagamentoQuotaNotification);
 * l'invio è best-effort: un fallimento non blocca la richiesta.
 */
class RichiestaSocioRicevuta extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Account $account,
        public string $motivazione,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->account->associazione;

        $message = (new MailMessage)
            ->subject('Nuova richiesta "diventa socio" — '.($associazione->nome ?? 'Frontend pubblico'))
            ->greeting('Ciao,')
            ->line("l'account {$this->account->nome_completo} ({$this->account->email}) ha chiesto di diventare socio.")
            ->line('Motivazione: "'.$this->motivazione.'"')
            ->line('Puoi gestire la richiesta dalla sezione Account del gestionale.')
            ->salutation('Il gestionale');

        return TenantNotificationMailer::applica($message, $associazione);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'richiesta_socio',
            'account_id' => $this->account->id,
            'account_nome' => $this->account->nome_completo,
            'account_email' => $this->account->email,
            'motivazione' => $this->motivazione,
            'richiesta_socio_at' => $this->account->richiesta_socio_at?->toIso8601String(),
        ];
    }
}
