<?php

namespace App\Notifications\Iscrizioni;

use App\Models\RichiestaIscrizione;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Richiesta di iscrizione socio approvata dall'admin: l'account è ora
 * collegato al socio attivo; se l'admin ha generato anche la tessera,
 * il numero viene riportato nell'email.
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

        if ($associazione !== null && filled($associazione->slug) && Route::has('pubblico.account.tessera')) {
            $message->action(
                'Vai alla tua area riservata',
                route('pubblico.account.tessera', ['associazione' => $associazione->slug])
            );
        }

        return TenantNotificationMailer::applica(
            $message->salutation('A presto!'),
            $associazione
        );
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
