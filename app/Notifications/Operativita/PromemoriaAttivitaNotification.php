<?php

namespace App\Notifications\Operativita;

use App\Models\AttivitaAdmin;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Promemoria attività admin (f.4 del piano "Gestione 2"): inviato
 * all'assegnatario il giorno della scadenza dal comando schedulato
 * attivita:promemoria. Layout email brandizzato per tenant + SMTP del
 * tenant quando configurato (pattern round 1, f.18).
 */
class PromemoriaAttivitaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AttivitaAdmin $attivita) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Promemoria attività: '.$this->attivita->titolo)
            ->greeting('Ciao '.($notifiable->nome ?? '').',')
            ->line("l'attività \"{$this->attivita->titolo}\" scade oggi"
                .($this->attivita->scadenza !== null ? ' ('.$this->attivita->scadenza->format('d/m/Y').')' : '').'.')
            ->lineIf(filled($this->attivita->descrizione), (string) $this->attivita->descrizione)
            ->salutation('— '.config('app.name'));

        if (Route::has('attivita-admin.index')) {
            $message->action('Vai alle attività', route('attivita-admin.index', ['assegnatario' => 'mie']));
        }

        return TenantNotificationMailer::applica(
            $message,
            $this->attivita->associazione()->withoutGlobalScopes()->first()
        );
    }
}
