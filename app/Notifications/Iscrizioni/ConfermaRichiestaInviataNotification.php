<?php

namespace App\Notifications\Iscrizioni;

use App\Models\Account;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Conferma immediata "richiesta ricevuta" al richiedente (f.19 del piano
 * Vita sociale): inviata all'Account autenticato oppure via mail
 * all'ospite (AnonymousNotifiable) subito dopo l'invio del form pubblico
 * /a/{slug}/iscriviti, con il riepilogo dei dati inviati.
 *
 * In modalità diretta (richiesta già approvata) il testo conferma
 * l'iscrizione attiva invece dell'attesa di valutazione.
 */
class ConfermaRichiestaInviataNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RichiestaIscrizione $richiesta) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // Account: anche campanellina in area riservata; ospite: solo mail
        return $notifiable instanceof Account ? ['database', 'mail'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $associazione = $this->richiesta->associazione;
        $nomeAssociazione = $associazione->nome ?? 'Associazione';
        $socio = $this->socio();

        $nome = $notifiable instanceof Account
            ? (string) $notifiable->nome
            : (string) ($socio->nome ?? '');

        $message = (new MailMessage)
            ->subject('Richiesta di iscrizione ricevuta — '.$nomeAssociazione)
            ->greeting('Ciao'.($nome !== '' ? ' '.$nome : '').',')
            ->line('abbiamo ricevuto la tua richiesta di iscrizione a "'.$nomeAssociazione.'".');

        if ($this->richiesta->isApprovata()) {
            $message->line('La tua iscrizione è già attiva: benvenuto/a tra i soci!');
        } else {
            $message->line('L\'associazione la valuterà al più presto: riceverai una risposta via email.');
        }

        $message->line('Riepilogo dei dati inviati:');

        foreach ($this->riepilogo() as $riga) {
            $message->line('• '.$riga);
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
            'tipo' => 'conferma_richiesta_iscrizione',
            'richiesta_id' => $this->richiesta->id,
            'socio_id' => $this->richiesta->socio_id,
            'stato' => $this->richiesta->stato,
        ];
    }

    /**
     * Righe "Etichetta: valore" del riepilogo: anagrafica del socio
     * preiscritto + snapshot dati_form (label italiane dei campi standard,
     * chiavi tecniche/sensibili escluse).
     *
     * @return list<string>
     */
    public function riepilogo(): array
    {
        $righe = [];
        $socio = $this->socio();

        if ($socio !== null) {
            $righe[] = 'Nome: '.trim(($socio->nome ?? '').' '.($socio->cognome ?? ''));

            if (filled($socio->email)) {
                $righe[] = 'Email: '.$socio->email;
            }
        }

        $dati = $this->richiesta->dati_form ?? [];

        // Chiavi già mostrate sopra o tecniche (honeypot/consenso)
        $escluse = ['nome', 'cognome', 'email', 'privacy'];

        foreach ($dati as $campo => $valore) {
            if (in_array($campo, $escluse, true) || is_array($valore) || blank($valore)) {
                continue;
            }

            $label = FormIscrizioneService::LABELS[$campo]
                ?? ucfirst(str_replace('_', ' ', (string) $campo));

            $righe[] = $label.': '.$valore;
        }

        return $righe;
    }

    /**
     * Socio preiscritto della richiesta (senza scope tenant: la notifica
     * può girare su un worker fuori contesto). Usa la relation se già
     * caricata (anteprime su modelli in memoria).
     */
    protected function socio(): ?Socio
    {
        if ($this->richiesta->relationLoaded('socio')) {
            /** @var Socio|null */
            return $this->richiesta->getRelation('socio');
        }

        if ($this->richiesta->socio_id === null) {
            return null;
        }

        return Socio::allTenants()->withTrashed()->find($this->richiesta->socio_id);
    }
}
