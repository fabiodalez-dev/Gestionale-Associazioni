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
 * Notifica agli amministratori del tenant: è arrivata una nuova richiesta
 * di iscrizione socio dal form pubblico /a/{slug}/iscriviti
 * (PIANO-ISCRIZIONI §Notifiche). Il preiscritto è già stato creato come
 * Socio "In Attesa"; l'esito va dato dalla pagina Richieste iscrizione.
 *
 * database + mail per tenant (pattern DaApprovareNotification);
 * invio best-effort, mai dentro la transazione di dominio
 * (IscrizioneController::notificaAdmin fa da rete di protezione).
 */
class RichiestaIscrizioneRicevutaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RichiestaIscrizione $richiesta) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $account = $this->richiesta->account()->withoutGlobalScopes()->first();

        $richiedente = trim(($account->nome ?? '').' '.($account->cognome ?? ''));
        $richiedente = $richiedente !== '' ? $richiedente : 'Un utente registrato';

        $message = (new MailMessage)
            ->subject('Nuova richiesta di iscrizione socio')
            ->greeting('Ciao,')
            ->line($richiedente.($account?->email ? ' ('.$account->email.')' : '')
                .' ha inviato una richiesta di iscrizione dal modulo pubblico.')
            ->line('Il preiscritto è stato creato in stato "In Attesa": '
                .'approva o rifiuta la richiesta dalla pagina Richieste iscrizione.');

        if (Route::has('richieste-iscrizione.index')) {
            $message->action(
                'Gestisci le richieste',
                route('richieste-iscrizione.index')
            );
        }

        return TenantNotificationMailer::applica(
            $message->salutation('Il gestionale'),
            $this->richiesta->associazione
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $account = $this->richiesta->account()->withoutGlobalScopes()->first();

        return [
            'tipo' => 'richiesta_iscrizione_ricevuta',
            'richiesta_id' => $this->richiesta->id,
            'account_id' => $this->richiesta->account_id,
            'socio_id' => $this->richiesta->socio_id,
            'richiedente' => trim(($account->nome ?? '').' '.($account->cognome ?? '')),
            'email' => $account->email ?? null,
        ];
    }
}
