<?php

namespace App\Notifications\Iscrizioni;

use App\Models\RichiestaIscrizione;
use App\Models\Tessera;
use App\Services\Email\TenantNotificationMailer;
use App\Services\Tessere\TesseraPdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Richiesta di iscrizione socio approvata dall'admin: l'account è ora
 * collegato al socio attivo; se l'admin ha generato anche la tessera,
 * il numero viene riportato nell'email e il PDF della tessera viene
 * allegato (f.19 PIANO-VITA-SOCIALE, "benvenuto socio" con prossimi passi).
 *
 * Destinatario: l'Account del frontend pubblico. database + mail per
 * tenant (pattern PagamentoQuotaNotification), invio best-effort.
 */
class IscrizioneApprovataNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public RichiestaIscrizione $richiesta,
        public ?string $numeroTessera = null,
        public ?string $tesseraId = null,
        public ?string $linkPagamentoQuota = null,
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
        $associazione = $this->richiesta->associazione;

        $message = (new MailMessage)
            ->subject('Benvenuto tra i soci — '.($associazione->nome ?? 'Associazione'))
            ->greeting('Ciao '.($notifiable->nome ?? '').',')
            ->line('la tua richiesta di iscrizione a "'
                .($associazione->nome ?? 'Associazione')
                .'" è stata approvata: da oggi sei socio!');

        if (filled($this->numeroTessera)) {
            $message->line('Ti è stata assegnata la tessera numero '
                .$this->numeroTessera.', disponibile nella tua area riservata.');
        }

        if (filled($this->linkPagamentoQuota)) {
            $message->line('Per completare il tesseramento ti chiediamo di versare la quota associativa dell\'anno: puoi pagarla online in un minuto.')
                ->action('Paga la quota associativa', $this->linkPagamentoQuota);
        }

        if ($associazione !== null && filled($associazione->slug) && Route::has('pubblico.account.tessera')) {
            $message->action(
                'Vai alla tua area riservata',
                route('pubblico.account.tessera', ['associazione' => $associazione->slug])
            );
        }

        // Prossimi passi (f.19)
        $message->line('Prossimi passi: nella tua area riservata trovi tessera, quote ed eventuali pagamenti da completare.')
            ->line('Per qualsiasi dubbio rispondi a questa email o contatta l\'associazione.');

        $this->allegaTesseraPdf($message);

        return TenantNotificationMailer::applica(
            $message->salutation('A presto!'),
            $associazione
        );
    }

    /**
     * Allega il PDF della tessera generata in fase di approvazione
     * (best-effort: se la generazione fallisce l'email parte comunque,
     * la tessera resta scaricabile dall'area riservata).
     */
    protected function allegaTesseraPdf(MailMessage $message): void
    {
        if (blank($this->tesseraId)) {
            return;
        }

        try {
            /** @var Tessera|null $tessera */
            $tessera = Tessera::allTenants()->find($this->tesseraId);

            if ($tessera === null) {
                return;
            }

            $pdf = app(TesseraPdfRenderer::class)->render($tessera)->getContent();

            if (! is_string($pdf) || $pdf === '') {
                return;
            }

            $message->attachData(
                $pdf,
                'tessera-'.($this->numeroTessera ?: 'socio').'.pdf',
                ['mime' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error('IscrizioneApprovataNotification: PDF tessera non allegato', [
                'tessera_id' => $this->tesseraId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'iscrizione_approvata',
            'richiesta_id' => $this->richiesta->id,
            'socio_id' => $this->richiesta->socio_id,
            'numero_tessera' => $this->numeroTessera,
        ];
    }
}
