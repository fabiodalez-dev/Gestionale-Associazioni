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

    /**
     * Email di benvenuto dopo l'approvazione, indirizzata con il nome
     * dell'account o, per l'ospite, con quello del modulo.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->richiesta->associazione;

        // Account: nome dell'account; ospite (invio on-demand): nome dal modulo
        $nome = trim((string) ($notifiable->nome ?? $this->richiesta->dati_form['nome'] ?? ''));

        $message = (new MailMessage)
            ->subject('Benvenuto tra i soci — '.($associazione->nome ?? 'Associazione'))
            ->greeting('Ciao'.($nome !== '' ? ' '.$nome : '').',')
            ->line('la tua richiesta di iscrizione a "'
                .($associazione->nome ?? 'Associazione')
                .'" è stata approvata: da oggi sei socio!');

        // Iscrizione da ospite (richiesta senza account): non ha un'area
        // riservata a cui accedere, quindi niente rimandi né pulsante verso di essa
        $ospite = $this->richiesta->account_id === null;

        if (filled($this->numeroTessera)) {
            $message->line('Ti è stata assegnata la tessera numero '
                .$this->numeroTessera
                .($ospite ? '.' : ', disponibile nella tua area riservata.'));
        }

        $areaRiservata = ! $ospite && $associazione !== null && filled($associazione->slug) && Route::has('pubblico.account.tessera')
            ? route('pubblico.account.tessera', ['associazione' => $associazione->slug])
            : null;

        // MailMessage ha un solo pulsante (una seconda action() sovrascrive la
        // prima): per l'account il pulsante porta all'area riservata (dove c'è
        // "Paga ora") e il link di pagamento diretto resta nel testo; per
        // l'ospite il pulsante è il link di pagamento.
        if (filled($this->linkPagamentoQuota)) {
            $message->line('Per completare il tesseramento ti chiediamo di versare la quota associativa dell\'anno: puoi pagarla online in un minuto.');

            if ($areaRiservata !== null) {
                $message->line('Paga la quota associativa: '.$this->linkPagamentoQuota);
            } else {
                $message->action('Paga la quota associativa', $this->linkPagamentoQuota);
            }
        }

        if ($areaRiservata !== null) {
            $message->action('Vai alla tua area riservata', $areaRiservata);
        }

        // Prossimi passi (f.19)
        if (! $ospite) {
            $message->line('Prossimi passi: nella tua area riservata trovi tessera, quote ed eventuali pagamenti da completare.');
        }

        $message->line('Per qualsiasi dubbio rispondi a questa email o contatta l\'associazione.');

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
