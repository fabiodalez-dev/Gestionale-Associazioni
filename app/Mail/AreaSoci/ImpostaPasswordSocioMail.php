<?php

namespace App\Mail\AreaSoci;

use App\Models\Socio;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Link per impostare (o reimpostare) la password dell'area soci.
 * Miglioramento rispetto al legacy, che mostrava il link a schermo:
 * il link viene inviato SOLO via email, con URL basato su APP_URL (https).
 */
class ImpostaPasswordSocioMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Socio $socio,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        // Con la stessa email in più associazioni arrivano più email: il nome
        // nell'oggetto dice a quale iscrizione si riferisce ciascun link
        $associazione = $this->socio->associazione?->nome;

        return new Envelope(
            subject: 'Imposta la tua password — Area Soci'.(filled($associazione) ? ' di '.$associazione : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'area-soci.emails.imposta-password',
        );
    }
}
