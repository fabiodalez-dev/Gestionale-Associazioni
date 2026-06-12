<?php

namespace App\Notifications\AnagraficaPro;

use App\Models\DocumentoSocio;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Promemoria documenti socio in scadenza (f.23 PIANO-VITA-SOCIALE):
 * inviato dal comando documenti:promemoria-scadenze agli admin del tenant
 * (canali database+mail) e al socio stesso se ha un'email (solo mail).
 *
 * Digest per socio: una sola notifica con tutti i documenti in finestra.
 */
class DocumentiInScadenzaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, DocumentoSocio>  $documenti
     */
    public function __construct(
        public readonly Socio $socio,
        public readonly Collection $documenti,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // Admin (Utente): campanella in-app + email; Socio: solo email
        return $notifiable instanceof Utente ? ['database', 'mail'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $perSocio = $notifiable instanceof Socio;

        $mail = (new MailMessage)
            ->subject($perSocio
                ? 'Promemoria: documenti in scadenza'
                : "Documenti in scadenza per il socio {$this->socio->nome_completo}")
            ->greeting('Ciao!')
            ->line($perSocio
                ? 'Questi tuoi documenti sono in scadenza o scaduti:'
                : "Questi documenti del socio {$this->socio->nome_completo} (n. {$this->socio->numero_socio}) sono in scadenza o scaduti:");

        foreach ($this->documenti as $documento) {
            $tipo = $documento->tipo_documento
                ? (DocumentoSocio::TIPI_DOCUMENTO[$documento->tipo_documento] ?? $documento->tipo_documento)
                : 'Documento';
            $stato = $documento->isScaduto() ? 'SCADUTO' : 'in scadenza';

            $mail->line("- {$tipo}: {$documento->nome_file} — {$stato} il {$documento->data_scadenza?->format('d/m/Y')}");
        }

        if (! $perSocio) {
            $mail->action('Apri la scheda socio', route('soci.show', [$this->socio, 'tab' => 'documenti']));
        }

        return $mail->salutation('— '.config('app.name'));
    }

    /**
     * Payload per il canale database (solo admin).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'documenti_in_scadenza',
            'socio_id' => $this->socio->id,
            'socio_nome' => $this->socio->nome_completo,
            'documenti' => $this->documenti
                ->map(fn (DocumentoSocio $d) => [
                    'id' => $d->id,
                    'nome_file' => $d->nome_file,
                    'tipo_documento' => $d->tipo_documento,
                    'data_scadenza' => $d->data_scadenza?->toDateString(),
                ])->values()->all(),
        ];
    }
}
