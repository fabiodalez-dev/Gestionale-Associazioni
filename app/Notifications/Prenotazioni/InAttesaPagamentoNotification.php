<?php

namespace App\Notifications\Prenotazioni;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Prenotazione creata in attesa di pagamento: posti riservati per
 * 30 minuti, link alla pagina di pagamento (scelta gateway + countdown).
 */
class InAttesaPagamentoNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $importo = number_format((float) $this->prenotazione->importo_totale, 2, ',', '.');
        $scadenza = $this->prenotazione->scadenza_pagamento_at?->format('H:i \d\e\l d/m/Y');

        $message = $this->baseMail($notifiable, 'Completa il pagamento — '.$this->titoloEvento())
            ->line('abbiamo riservato '.$this->prenotazione->posti.' '
                .((int) $this->prenotazione->posti === 1 ? 'posto' : 'posti')
                .' per "'.$this->titoloEvento().'" del '.$this->dataEvento()
                .' (prenotazione '.$this->prenotazione->codice.').')
            ->line("Importo da pagare: € {$importo}.")
            ->action('Paga ora', $this->urlPagamento());

        if ($scadenza !== null) {
            $message->line("I posti restano riservati fino alle {$scadenza}: dopo, la prenotazione scade e i posti vengono liberati.");
        }

        return $message->salutation('A presto!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_in_attesa_pagamento'] + $this->datiBase() + [
            'importo_totale' => (string) $this->prenotazione->importo_totale,
            'scadenza_pagamento_at' => $this->prenotazione->scadenza_pagamento_at?->toIso8601String(),
        ];
    }
}
