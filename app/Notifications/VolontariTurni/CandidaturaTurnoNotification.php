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
 * Avvisa l'admin quando un socio si auto-candida a un turno (f.4 del piano
 * "Organizzazione 3"). Inviata solo quando la candidatura resta da
 * confermare (turno senza auto_conferma). Layout email brandizzato per
 * tenant + SMTP del tenant quando configurato.
 */
class CandidaturaTurnoNotification extends Notification implements ShouldQueue
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
            ->subject('Nuova candidatura volontario: '.$turno->titolo)
            ->greeting('Ciao,')
            ->line($socio->nome_completo
                .' si è candidato/a al turno "'.$turno->titolo.'" del '.$turno->data->format('d/m/Y').'.')
            ->line('La candidatura è in attesa di conferma.');

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
