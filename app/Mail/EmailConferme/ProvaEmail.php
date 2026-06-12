<?php

namespace App\Mail\EmailConferme;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invio di prova dal Centro anteprime email (f.20): inoltra all'admin
 * l'HTML già renderizzato dell'anteprima, con subject prefissato [Prova].
 */
class ProvaEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectAnteprima,
        public string $htmlAnteprima,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Prova] '.$this->subjectAnteprima,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlAnteprima,
        );
    }
}
