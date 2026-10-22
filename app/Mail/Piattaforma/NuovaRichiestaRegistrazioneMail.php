<?php

namespace App\Mail\Piattaforma;

use App\Models\RichiestaRegistrazione;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Avviso al super admin: un'associazione chiede di iscriversi.
 */
class NuovaRichiestaRegistrazioneMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public RichiestaRegistrazione $richiesta) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nuova richiesta di iscrizione: '.$this->richiesta->nome);
    }

    public function content(): Content
    {
        return new Content(view: 'piattaforma.mail.nuova-richiesta');
    }
}
