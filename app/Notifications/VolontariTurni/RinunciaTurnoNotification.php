<?php

namespace App\Notifications\VolontariTurni;

use App\Models\TurnoAssegnazione;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Avvisa l'admin quando un socio rinuncia a un turno (f.4 del piano
 * "Organizzazione 3"): lo slot viene riaperto e va ricoperto. Layout email
 * brandizzato per tenant + SMTP del tenant quando configurato.
 */
class RinunciaTurnoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TurnoAssegnazione $assegnazione) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $turno = $this->assegnazione->turno;
        $socio = $this->assegnazione->socio;

        $message = (new MailMessage)
            ->subject('Rinuncia volontario: '.$turno->titolo)
            ->greeting('Ciao,')
            ->line($socio->nome_completo
                .' ha rinunciato al turno "'.$turno->titolo.'" del '.$turno->data->format('d/m/Y').'.')
            ->lineIf(filled($this->assegnazione->motivo_rinuncia), 'Motivo: '.(string) $this->assegnazione->motivo_rinuncia)
            ->line('Lo slot è di nuovo disponibile.');

        if (Route::has('turni.show')) {
            $message->action('Gestisci il turno', route('turni.show', $turno));
        }

        $message->salutation('— '.config('app.name'));

        return TenantNotificationMailer::applica(
            $message,
            $this->assegnazione->associazione()->withoutGlobalScopes()->first()
        );
    }
}
