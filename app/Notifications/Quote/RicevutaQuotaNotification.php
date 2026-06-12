<?php

namespace App\Notifications\Quote;

use App\Models\Ricevuta;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use App\Services\Pagamenti\RicevutaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Ricevuta di pagamento quota numerata per anno (f.21 PIANO-VITA-SOCIALE):
 * inviata al socio alla conferma del pagamento con il PDF in allegato
 * (best-effort: se la generazione fallisce l'email parte comunque,
 * la ricevuta resta scaricabile dalle aree riservate).
 *
 * Pattern PagamentoQuotaNotification: database + mail con rispetto
 * dell'opt-out email del socio, layout brandizzato per tenant.
 */
class RicevutaQuotaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Ricevuta $ricevuta) {}

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
        $associazione = $this->ricevuta->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la tua associazione';
        $importo = number_format((float) $this->ricevuta->importo, 2, ',', '.');

        $message = (new MailMessage)
            ->subject("Ricevuta n. {$this->ricevuta->numero}/{$this->ricevuta->anno} — {$nomeAssociazione}")
            ->greeting('Ciao '.($notifiable instanceof Socio ? $notifiable->nome : '').',')
            ->line("in allegato trovi la ricevuta n. {$this->ricevuta->numero}/{$this->ricevuta->anno} per il pagamento della tua quota.")
            ->line("Importo: € {$importo}")
            ->line('Puoi scaricarla in qualsiasi momento anche dalla tua area riservata.')
            ->salutation('Grazie!');

        $this->allegaPdf($message);

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
            'tipo' => 'ricevuta_quota',
            'ricevuta_id' => $this->ricevuta->id,
            'quota_id' => $this->ricevuta->quota_id,
            'numero' => $this->ricevuta->numero,
            'anno' => $this->ricevuta->anno,
            'importo' => (string) $this->ricevuta->importo,
        ];
    }

    protected function allegaPdf(MailMessage $message): void
    {
        try {
            /** @var RicevutaService $ricevute */
            $ricevute = app(RicevutaService::class);

            $message->attachData(
                $ricevute->pdfContent($this->ricevuta),
                $ricevute->nomeFile($this->ricevuta),
                ['mime' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error('RicevutaQuotaNotification: PDF non allegato', [
                'ricevuta_id' => $this->ricevuta->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
