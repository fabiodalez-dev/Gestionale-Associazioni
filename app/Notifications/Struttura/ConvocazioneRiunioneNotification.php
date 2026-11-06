<?php

namespace App\Notifications\Struttura;

use App\Models\Riunione;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convocazione di una riunione (f.17 del piano "Organizzazione 3"): inviata
 * IN CODA dall'admin agli intestatari delle cariche attive (round 1) e agli
 * utenti invitati per il direttivo, agli aventi diritto per l'assemblea dei
 * soci (issue #6, con l'eventuale seconda convocazione). Layout brandizzato
 * per tenant (TenantNotificationMailer): titolo, data/ora, luogo e ordine del
 * giorno strutturato. Canali database + mail.
 */
class ConvocazioneRiunioneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Riunione $riunione,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->riunione->associazione;
        $nomeAssociazione = $associazione->nome ?? 'la tua associazione';
        $data = $this->riunione->data_ora->format('d/m/Y H:i');
        $luogo = $this->riunione->luogo
            ?? data_get($this->riunione->spazio, 'nome')
            ?? 'da definire';

        $nome = (string) ($notifiable->nome ?? '');

        if ($this->riunione->isAssemblea()) {
            $organo = mb_strtolower($this->riunione->organoLabel());

            $message = (new MailMessage)
                ->subject("Convocazione {$organo} — {$this->riunione->titolo}")
                ->greeting(trim('Ciao '.$nome))
                ->line("Sei convocato/a all'{$organo} dei soci di {$nomeAssociazione}: \"{$this->riunione->titolo}\".")
                ->line("Prima convocazione: {$data}");

            if ($this->riunione->seconda_convocazione_at !== null) {
                $message->line('Seconda convocazione: '.$this->riunione->seconda_convocazione_at->format('d/m/Y H:i'));
            }

            $message->line("Luogo: {$luogo}");
        } else {
            $message = (new MailMessage)
                ->subject("Convocazione riunione — {$this->riunione->titolo}")
                ->greeting(trim('Ciao '.$nome))
                ->line("Sei convocato/a alla riunione \"{$this->riunione->titolo}\" del direttivo di {$nomeAssociazione}.")
                ->line("Data e ora: {$data}")
                ->line("Luogo: {$luogo}");
        }

        $odg = is_array($this->riunione->odg) ? $this->riunione->odg : [];

        if ($odg !== []) {
            $message->line('Ordine del giorno:');

            foreach ($odg as $i => $punto) {
                $titoloPunto = is_array($punto) ? (string) ($punto['punto'] ?? '') : (string) $punto;

                if ($titoloPunto !== '') {
                    $message->line(($i + 1).'. '.$titoloPunto);
                }
            }
        }

        $message->salutation("A presto, {$nomeAssociazione}");

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
            'tipo' => 'convocazione_riunione',
            'riunione_id' => $this->riunione->id,
            'titolo' => $this->riunione->titolo,
            'data_ora' => $this->riunione->data_ora->toIso8601String(),
            'luogo' => $this->riunione->luogo ?? $this->riunione->spazio?->nome,
        ];
    }
}
