<?php

namespace App\Notifications\Struttura;

use App\Models\DelegaRiunione;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delega per l'assemblea registrata (issue #7): al delegato come avviso
 * ("hai ricevuto una delega") e al delegante come ricevuta. Comunicazione
 * di servizio legata all'assemblea: non dipende dall'opt-out newsletter.
 */
class DelegaAssembleaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'delegante'|'delegato'  $destinatario
     */
    public function __construct(
        public DelegaRiunione $delega,
        public string $destinatario,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $riunione = $this->delega->riunione;
        $associazione = $riunione->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la tua associazione';
        $assemblea = mb_strtolower($riunione->organoLabel());
        $data = $riunione->data_ora->format('d/m/Y H:i');

        $message = (new MailMessage)->greeting(trim('Ciao '.($notifiable->nome ?? '')));

        if ($this->destinatario === 'delegato') {
            $message->subject("Hai ricevuto una delega — {$riunione->titolo}")
                ->line("{$this->delega->delegante->nome_completo} ti ha delegato a rappresentarlo/a all'{$assemblea} \"{$riunione->titolo}\" del {$data}.")
                ->line('Per far valere la delega devi essere presente all\'assemblea: voterai anche a suo nome.');
        } else {
            $message->subject("Delega registrata — {$riunione->titolo}")
                ->line("La tua delega a {$this->delega->delegato->nome_completo} per l'{$assemblea} \"{$riunione->titolo}\" del {$data} è stata registrata.")
                ->line('Se poi partecipi di persona, la delega decade e voti tu.');
        }

        $message->salutation("A presto, {$nomeAssociazione}");

        return TenantNotificationMailer::applica(
            $message,
            $associazione,
            $notifiable instanceof Socio ? $notifiable : null
        );
    }
}
