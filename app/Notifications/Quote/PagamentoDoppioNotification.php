<?php

namespace App\Notifications\Quote;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Pagamento online arrivato per una quota già pagata (#62): l'associazione
 * ha incassato due volte e deve decidere se rimborsare o tenerlo come
 * credito. Agli admin dell'associazione, su database e mail.
 */
class PagamentoDoppioNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $quotaId,
        public string $socio,
        public int $anno,
        public string $importo,
        public string $gateway,
        public string $transazione,
        public string $pagamentoPrecedente,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $messaggio = (new MailMessage)
            ->error()
            ->subject("Quota pagata due volte: {$this->socio}, anno {$this->anno}")
            ->greeting('Pagamento da verificare')
            ->line("È arrivato un pagamento online di € {$this->importo} via {$this->gateway} (transazione {$this->transazione}) per la quota {$this->anno} di {$this->socio}, che risultava già pagata: {$this->pagamentoPrecedente}.")
            ->line('Non è stata emessa una seconda ricevuta. Verifica sul conto del gateway e decidi se rimborsare il pagamento o tenerlo come anticipo.');

        if (Route::has('quote.index')) {
            $messaggio->action('Apri le quote', route('quote.index'));
        }

        return $messaggio;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'pagamento_doppio',
            'quota_id' => $this->quotaId,
            'socio' => $this->socio,
            'anno' => $this->anno,
            'importo' => $this->importo,
            'gateway' => $this->gateway,
            'transazione' => $this->transazione,
            'pagamento_precedente' => $this->pagamentoPrecedente,
        ];
    }
}
