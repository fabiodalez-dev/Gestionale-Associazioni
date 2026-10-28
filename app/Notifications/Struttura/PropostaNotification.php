<?php

namespace App\Notifications\Struttura;

use App\Models\Proposta;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Cambio di stato di una proposta dei soci (issue #10): al proponente e agli
 * aderenti (soci) oppure, al raggiungimento della soglia, alla segreteria
 * (utenti, anche nelle notifiche del gestionale). Il testo dipende dallo
 * stato della proposta al momento dell'invio.
 */
class PropostaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $stato;

    public function __construct(
        public Proposta $proposta,
    ) {
        $this->stato = $proposta->stato;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $canali = $notifiable instanceof Utente ? ['database'] : [];

        if (filled($notifiable->email ?? null)) {
            $canali[] = 'mail';
        }

        return $canali;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->proposta->associazione;
        $titolo = $this->proposta->titolo;

        $message = (new MailMessage)->greeting(trim('Ciao '.($notifiable->nome ?? '')));

        if ($notifiable instanceof Utente) {
            $message->subject("Proposta da esaminare — {$titolo}")
                ->line("La proposta dei soci \"{$titolo}\" ha raggiunto le adesioni necessarie e va esaminata.");
        } else {
            $message->subject("Aggiornamento sulla proposta — {$titolo}")
                ->line($this->rigaSocio());

            if (filled($this->proposta->motivazione) && in_array($this->stato, [Proposta::STATO_NON_AMMESSA, Proposta::STATO_ACCOLTA, Proposta::STATO_RESPINTA], true)) {
                $message->line('Motivazione: '.$this->proposta->motivazione);
            }
        }

        $message->salutation('A presto, '.($associazione->nome ?? 'la tua associazione'));

        return TenantNotificationMailer::applica($message, $associazione, $notifiable instanceof Socio ? $notifiable : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'proposta',
            'proposta_id' => $this->proposta->id,
            'titolo' => $this->proposta->titolo,
            'stato' => $this->stato,
        ];
    }

    private function rigaSocio(): string
    {
        $titolo = $this->proposta->titolo;

        return match ($this->stato) {
            Proposta::STATO_PUBBLICATA => "La proposta \"{$titolo}\" è pubblicata: ora gli altri soci possono aderire.",
            Proposta::STATO_NON_AMMESSA => "La proposta \"{$titolo}\" non è stata ammessa.",
            Proposta::STATO_DA_ESAMINARE => "La proposta \"{$titolo}\" ha raggiunto le adesioni necessarie: sarà esaminata.",
            Proposta::STATO_IN_ODG => "La proposta \"{$titolo}\" è all'ordine del giorno della riunione \"{$this->proposta->riunione?->titolo}\" del ".$this->proposta->riunione?->data_ora?->format('d/m/Y').'.',
            Proposta::STATO_ACCOLTA => "La proposta \"{$titolo}\" è stata accolta.",
            Proposta::STATO_RESPINTA => "La proposta \"{$titolo}\" è stata respinta.",
            default => "La proposta \"{$titolo}\" è stata aggiornata.",
        };
    }
}
