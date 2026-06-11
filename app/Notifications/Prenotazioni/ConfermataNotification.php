<?php

namespace App\Notifications\Prenotazioni;

use App\Services\Prenotazioni\BigliettoService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

/**
 * Prenotazione confermata: email con il biglietto PDF in allegato
 * (best-effort: se la generazione del PDF fallisce l'email parte
 * comunque, il biglietto resta scaricabile dall'area riservata).
 */
class ConfermataNotification extends PrenotazioneNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->baseMail($notifiable, 'Prenotazione confermata — '.$this->titoloEvento())
            ->line('la tua prenotazione '.$this->prenotazione->codice
                .' per "'.$this->titoloEvento().'" del '.$this->dataEvento().' è confermata!')
            ->line('Posti prenotati: '.$this->prenotazione->posti.'.')
            ->action('Vedi la prenotazione', $this->urlDettaglio())
            ->line('Presenta il QR code del biglietto all\'ingresso per il check-in.')
            ->salutation('A presto!');

        $this->allegaBiglietto($message);

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'prenotazione_confermata'] + $this->datiBase();
    }

    protected function allegaBiglietto(MailMessage $message): void
    {
        try {
            /** @var BigliettoService $biglietti */
            $biglietti = app(BigliettoService::class);

            $message->attachData(
                $biglietti->pdfBinary($this->prenotazione),
                $biglietti->pdfFilename($this->prenotazione),
                ['mime' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error('ConfermataNotification: generazione biglietto PDF fallita', [
                'prenotazione_id' => $this->prenotazione->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
